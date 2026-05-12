<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToOrganization
{
    /**
     * Boot the BelongsToOrganization trait for a model.
     *
     * @return void
     */
    protected static function bootBelongsToOrganization(): void
    {
        // Add a global scope to automatically filter by the current organization
        static::addGlobalScope('organization', function (Builder $builder) {
            if (app()->has('currentOrganization')) {
                $builder->where('organization_id', app('currentOrganization')->id);
            }
        });

        // Automatically set the organization_id upon model creation
        static::creating(function ($model) {
            // Only set if not already manually set, and we have an organization in the container
            if (empty($model->organization_id) && app()->has('currentOrganization')) {
                $model->organization_id = app('currentOrganization')->id;
            }
        });
    }

    /**
     * Relationship to the Organization model.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function organization()
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }
}
