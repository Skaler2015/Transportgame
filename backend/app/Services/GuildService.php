<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Guild;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Guild (alliance) lifecycle: found, join, leave, contribute to the treasury.
 */
class GuildService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function create(Company $company, string $name, string $tag): Guild
    {
        if ($company->guild_id) {
            throw new RuntimeException('Leave your current guild first.');
        }
        $cost = config('transoria.guild.create_cost');
        if ($company->cash < $cost) {
            throw new RuntimeException('Not enough cash to found a guild.');
        }
        $tag = strtoupper(trim($tag));
        if (! preg_match('/^[A-Z0-9]{2,6}$/', $tag)) {
            throw new RuntimeException('Tag must be 2–6 letters/numbers.');
        }
        if (Guild::where('tag', $tag)->exists()) {
            throw new RuntimeException('That tag is taken.');
        }

        return DB::transaction(function () use ($company, $name, $tag, $cost) {
            $guild = Guild::create([
                'name' => $name,
                'tag' => $tag,
                'slug' => $this->uniqueSlug($name),
                'owner_company_id' => $company->id,
                'emblem_color' => $company->logo_color,
                'member_count' => 1,
                'total_reputation' => $company->reputation,
            ]);

            $company->guild_id = $guild->id;
            $company->guild_role = 'owner';
            $company->save();

            $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
                "Founded guild {$name}", -$cost, $guild);

            return $guild;
        });
    }

    public function join(Company $company, Guild $guild): Guild
    {
        if ($company->guild_id) {
            throw new RuntimeException('Leave your current guild first.');
        }
        if ($guild->member_count >= config('transoria.guild.max_members')) {
            throw new RuntimeException('That guild is full.');
        }

        $company->guild_id = $guild->id;
        $company->guild_role = 'member';
        $company->save();
        $guild->recalculate();

        return $guild->fresh();
    }

    public function leave(Company $company): void
    {
        $guild = $company->guild;
        if (! $guild) {
            throw new RuntimeException('You are not in a guild.');
        }

        $isOwner = $guild->owner_company_id === $company->id;
        $others = $guild->members()->where('id', '!=', $company->id)->count();

        if ($isOwner && $others > 0) {
            throw new RuntimeException('Transfer ownership or remove members before leaving.');
        }

        DB::transaction(function () use ($company, $guild, $isOwner, $others) {
            $company->guild_id = null;
            $company->guild_role = null;
            $company->save();

            if ($isOwner && $others === 0) {
                $guild->delete();
            } else {
                $guild->recalculate();
            }
        });
    }

    public function contribute(Company $company, int $amountCents): Guild
    {
        $guild = $company->guild;
        if (! $guild) {
            throw new RuntimeException('You are not in a guild.');
        }
        if ($amountCents < 1 || $company->cash < $amountCents) {
            throw new RuntimeException('Invalid contribution amount.');
        }

        DB::transaction(function () use ($company, $guild, $amountCents) {
            $this->ledger->post($company, LedgerEntry::CAT_PURCHASE,
                "Guild contribution to {$guild->name}", -$amountCents, $guild);
            $company->guild_contribution += $amountCents;
            $company->save();
            $guild->increment('treasury', $amountCents);
        });

        return $guild->fresh();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'guild';
        $slug = $base;
        $n = 1;
        while (Guild::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
