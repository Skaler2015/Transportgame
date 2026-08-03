<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Company;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesCompany
{
    /** The authenticated user's company, or 409 if they haven't founded one. */
    protected function company(Request $request): Company
    {
        $company = $request->user()?->company;

        if (! $company) {
            throw new HttpException(409, 'You have not founded a company yet.');
        }

        return $company;
    }
}
