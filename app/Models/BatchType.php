<?php

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchType extends Model
{
    use BelongsToCompany;

    protected $fillable = [
  'company_id',
  'name',
  'code',
  'description',
  'color',
  'icon',
  'is_active',
 ];


 protected $casts = [
  'is_active' => 'boolean',
 ];

 public function company(): BelongsTo
 {
  return $this->belongsTo(Company::class);
 }

 public function batches():HasMany
 {
  return $this->hasMany(Batch::class);
 }


  
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByCode($query, $code)
    {
        return $query->where('code', $code);
    }

 public function isOperational():bool
 {
  return $this->code === 'OPERATIONAL';
 }
 public function isQuarantine():bool
 {
  return $this->code === 'QUARANTINE';
 }


}
