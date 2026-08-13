<?php

namespace App\Http\Requests;

class UpdateDevelopmentModelPackageRequest extends StoreDevelopmentModelPackageRequest
{
    /**
     * Exclude the package being edited from the overlap check.
     */
    protected function ignorePackageId(): ?int
    {
        return $this->route('developmentModelPackage')?->id;
    }
}
