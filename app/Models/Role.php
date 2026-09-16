<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Role extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'roles';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'description',
        'is_active',
        'company_id',
    ];

    protected $casts = [
        'id' => 'string',
        'company_id' => 'string',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'role_id', 'id');
    }

    /**
     * Permissions assigned to this role (many-to-many)
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id')
                    ->withPivot('granted_by', 'granted_at')
                    ->withTimestamps();
    }

    /**
     * Get role permissions as an array of keys
     */
    public function getPermissionKeys()
    {
        return $this->permissions()
                    ->whereRaw('permissions.is_active = true')
                    ->pluck('permissions.key')
                    ->toArray();
    }

    /**
     * Check if role has a specific permission
     */
    public function hasPermission($permissionKey)
    {
        return $this->permissions()
                    ->where('permissions.key', $permissionKey)
                    ->whereRaw('permissions.is_active = true')
                    ->exists();
    }

    /**
     * Assign permission to role
     */
    public function assignPermission($permissionId, $grantedBy = null)
    {
        if (!$this->permissions()->where('permission_id', $permissionId)->exists()) {
            $this->permissions()->attach($permissionId, [
                'granted_by' => $grantedBy ?? auth()->id(),
                'granted_at' => now(),
            ]);
        }
    }

    /**
     * Remove permission from role
     */
    public function removePermission($permissionId)
    {
        $this->permissions()->detach($permissionId);
    }


    // Removed legacy permissions attribute (no longer needed)

    // Boolean mutators for PostgreSQL compatibility
    public function setIsActiveAttribute($value)
    {
        // Store as 'true'/'false' string for PostgreSQL compatibility
        if ($value === null) {
            $this->attributes['is_active'] = 'false';
        } else {
            $this->attributes['is_active'] = ($value === true || $value === 'true' || $value === 1 || $value === '1') ? 'true' : 'false';
        }
    }

    // Scopes for PostgreSQL boolean queries
    public function scopeActive($query)
    {
        return $query->whereRaw('is_active = true');
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

}
