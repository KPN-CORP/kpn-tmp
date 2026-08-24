<?php

namespace App\Http\Requests;

/**
 * Update shares the create rules verbatim — packages may overlap in period and
 * audience (resolution disambiguates), so there is nothing to exclude.
 */
class UpdateDevelopmentModelPackageRequest extends StoreDevelopmentModelPackageRequest {}
