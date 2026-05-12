<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InviteUserRequest;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Mail\InvitationEmail;
use App\Models\LicenseActivation;
use App\Models\Organization;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\AppHelper;
use App\Services\ActivityLogger;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $data = app('currentOrganization');
        if( $data['logo'] ){
            $data['logo'] = url('images/'.$data['slug'].'/'.$data['logo']);
        } else {
            $data['logo'] = AppHelper::getSiteLogo();
        }

        return response()->json([
            'status' => true,
            'data' => $data,
            'message' => 'Welcome to the RiseLMS API',
        ]);
    }

    /**
     * Verify if a slug is available.
     */
    public function verifySlug(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'slug' => 'required|alpha_dash|unique:organizations,slug',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Slug is available'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreOrganizationRequest $request)
    {
        $authUser = auth()->user();

        //If user submitted logo, upload this and get the path
        $fileName = $this->uploadLogo($request, $request->slug, null);

        // Create the organization
        $organization = Organization::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'logo' => $fileName,
            'website' => $request->website,
            'industry' => $request->industry,
            'size' => $request->company_size,
            'description' => $request->description,
            'created_by' => $authUser->id,
        ]);

        // Update authenticated user's organization_id
        $authUser->update([
            'organization_id' => $organization->id,
            'is_admin' => true,
        ]);

        // Create a default admin role for this organization
        $adminRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Admin',
            'description' => 'Organization Administrator',
        ]);

        // Grant all existing permissions to the default Admin role.
        $adminRole->permissions()->sync(Permission::query()->pluck('id')->toArray());

        // Assign the admin role to the authenticated user
        $authUser->assignRoles([$adminRole->id]);

        // Create a 14-day free trial license for the new organization
        LicenseActivation::create([
            'user_id'                 => $authUser->id,
            'organization_id'        => $organization->id,
            'provider'               => 'trial',
            'exchange_code_hash'     => hash('sha256', 'trial_' . $organization->id . '_' . $authUser->id),
            'provider_purchase_id'   => null,
            'provider_customer_email'=> $authUser->email,
            'plan_code'              => 'free_trial',
            'status'                 => 'active',
            'activated_at'           => now(),
            'expires_at'             => now()->addDays(14),
            'payload'                => [
                'billing_cycle'  => 'trial',
                'customer_email' => $authUser->email,
                'trial_days'     => 14,
            ],
        ]);

        return response()->json([
            'status' => true,
            'organization' => $organization
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateOrganizationRequest $request, string $id)
    {
        $organization = Organization::findOrFail($id);
        $user = auth()->user();

        // Superadmin can update any org. Admin can update only own org.
        if ($user->role !== 'superadmin' && $user->organization_id !== $organization->id) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 403);
        }

        $organization = $this->persistOrganizationUpdate($request, $organization);

        return response()->json([
            'status' => true,
            'organization' => $organization,
        ]);
    }

    /**
     * Update current tenant organization profile.
     */
    public function updateMe(UpdateOrganizationRequest $request)
    {
        $organization = app('currentOrganization');
        $user = auth()->user();

        if ($user->role !== 'admin' || (int) $user->organization_id !== (int) $organization->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $organization = $this->persistOrganizationUpdate($request, $organization);

        return response()->json([
            'status' => true,
            'message' => 'Organization updated successfully.',
            'organization' => $organization,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $organization = Organization::findOrFail($id);

        // Check if user is admin of this organization
        if (auth()->user()->role !== 'admin' || auth()->user()->organization_id !== $organization->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Delete all users in the organization
        $organization->users()->delete();

        // Delete the organization
        $organization->delete();

        return response()->json(['message' => 'Organization deleted']);
    }

    /**
     * Get current organization.
     */
    public function me()
    {
        return response()->json(app('currentOrganization'));
    }

    private function uploadLogo(Request $request, $path = null, $oldFileName = null, $oldPath = null)
    {
        $file = $this->resolveLogoUpload($request);

        if ($file) {

            $fileName = time() . '.' . $file->getClientOriginalExtension();
            $path = 'images/' . $path;
            $destinationPath = public_path($path);

            if (!is_dir($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            // upload file
            $file->move($destinationPath, $fileName);

            // delete old file
            $deleteFromPath = 'images/' . ($oldPath ?: trim(str_replace('images/', '', $path), '/'));
            $oldFilePath = public_path($deleteFromPath . '/' . $oldFileName);
            if ($oldFileName && file_exists($oldFilePath)) {
                unlink($oldFilePath);
            }

            return $fileName;
        }

        return $oldFileName;
    }

    private function resolveLogoUpload(Request $request): ?UploadedFile
    {
        if ($request->hasFile('logo')) {
            return $request->file('logo');
        }

        if ($this->isLogoContentRequest($request)) {
            $content = (string) $request->input('logo');

            return $this->makeUploadedFileFromContent(
                $content,
                $request->input('filename', 'upload.bin')
            );
        }

        return null;
    }

    private function makeUploadedFileFromContent(string $content, string $filename): UploadedFile
    {
        if (str_contains($content, ',')) {
            [, $content] = explode(',', $content, 2);
        }

        $decoded = base64_decode($content, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid logo content.');
        }

        $path = tempnam(sys_get_temp_dir(), 'logo_');
        file_put_contents($path, $decoded);

        return new UploadedFile($path, $filename, null, null, true);
    }

    private function isLogoContentRequest(Request $request): bool
    {
        $content = $request->input('logo');

        if (!is_string($content) || trim($content) === '') {
            return false;
        }

        if ($request->filled('filename') || str_starts_with(trim($content), 'data:image/')) {
            return true;
        }

        // Preserve existing update behavior where logo can be a path string.
        return false;
    }

    /**
     * Persist tenant/central organization profile updates.
     */
    private function persistOrganizationUpdate(UpdateOrganizationRequest $request, Organization $organization): Organization
    {
        $oldSlug = $organization->slug;
        $newSlug = $request->input('slug', $oldSlug);

        $updatePayload = [
            'name' => $request->input('name'),
            'slug' => $newSlug,
        ];

        if ($request->hasFile('logo') || $this->isLogoContentRequest($request)) {
            $updatePayload['logo'] = $this->uploadLogo($request, $newSlug, $organization->logo, $oldSlug);
        } elseif ($request->has('logo')) {
            $updatePayload['logo'] = $request->input('logo');
        } elseif ($oldSlug !== $newSlug) {
            $updatePayload['logo'] = $this->replaceLogoSlugSegment($organization->logo, $oldSlug, $newSlug);
        }

        if ($request->has('website')) {
            $updatePayload['website'] = $request->input('website');
        }

        if ($request->has('industry')) {
            $updatePayload['industry'] = $request->input('industry');
        }

        if ($request->has('company_size')) {
            $updatePayload['size'] = $request->input('company_size');
        } elseif ($request->has('size')) {
            $updatePayload['size'] = $request->input('size');
        }

        if ($request->has('description')) {
            $updatePayload['description'] = $request->input('description');
        }

        $organization->update($updatePayload);

        return $organization->fresh();
    }

    /**
     * Replace organization slug segment in a stored logo path.
     */
    private function replaceLogoSlugSegment(?string $path, string $oldSlug, string $newSlug): ?string
    {
        if (empty($path) || $oldSlug === $newSlug) {
            return $path;
        }

        $hasLeadingSlash = str_starts_with($path, '/');
        $segments = explode('/', trim($path, '/'));

        foreach ($segments as $index => $segment) {
            if ($segment === $oldSlug) {
                $segments[$index] = $newSlug;
                break;
            }
        }

        $updatedPath = implode('/', $segments);

        return $hasLeadingSlash ? '/' . $updatedPath : $updatedPath;
    }

    /**
     * Toggle activity logs on/off for the current organization.
     */
    public function toggleActivityLogs(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $organization = app('currentOrganization');
        $organization->update(['activity_logs_enabled' => $request->boolean('enabled')]);

        $state = $request->boolean('enabled') ? 'enabled' : 'disabled';

        // Log this action even if logging is being disabled (last log before off)
        ActivityLog::create([
            'organization_id' => $organization->id,
            'user_id'         => auth()->id(),
            'action'          => 'toggle_activity_logs',
            'module'          => 'settings',
            'description'     => "Activity logs {$state} for organization \"{$organization->name}\"",
            'properties'      => ['enabled' => $request->boolean('enabled')],
            'ip_address'      => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'created_at'      => now(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => "Activity logs {$state} successfully.",
            'data'    => ['activity_logs_enabled' => $request->boolean('enabled')],
        ]);
    }
}