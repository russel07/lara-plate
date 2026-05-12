<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;

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
        $request->validate([
            'slug' => 'required|alpha_dash|unique:organizations,slug',
        ]);

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
            'training_goal' => $request->training_goal,
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
}