<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'connection_id', 'external_company_id', 'activated_at', 'synced_at', 'error', 'revision'])]
class SnipeItSync extends Model
{
    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'synced_at' => 'datetime', 'external_company_id' => 'integer', 'revision' => 'integer'];
    }

    public function assets(): HasMany
    {
        return $this->hasMany(SnipeItAsset::class, 'sync_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SnipeItConnection::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Asset count per view (a partition of the assets) plus assets needing attention per view. */
    public function counts(): array
    {
        $problems = collect(['pending', 'linked', 'ipam'])
            ->mapWithKeys(fn ($view) => [$view => $this->assets()->forView($view)->withProblem()->count()]);

        return [
            ...collect(SnipeItAsset::VIEWS)->mapWithKeys(fn ($view) => [$view => $this->assets()->forView($view)->count()])->all(),
            'problems' => $problems->sum(),
            'problems_by_view' => $problems->all(),
        ];
    }

    public static function current(Company $company): ?self
    {
        $link = SnipeItCompanyLink::where('company_id', $company->id)->latest('id')->first();

        return $link ? static::where('company_id', $company->id)->where('connection_id', $link->connection_id)
            ->where('external_company_id', $link->external_company_id)->first() : null;
    }

    public function isActive(): bool
    {
        $setting = SnipeItSetting::forCompany($this->company);
        $connectionId = $setting->use_global ? SnipeItSetting::forCompany(null)->connection_id : $setting->connection_id;
        $link = SnipeItCompanyLink::where('company_id', $this->company_id)->latest('id')->first();

        return $connectionId === $this->connection_id && $link?->confirmed_at !== null
            && $link->connection_id === $this->connection_id && $link->external_company_id === $this->external_company_id;
    }
}
