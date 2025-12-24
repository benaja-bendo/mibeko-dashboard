<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Institution;
use App\Http\Resources\V1\InstitutionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Institutions
 */
class InstitutionController extends Controller
{
    /**
     * List institutions.
     */
    public function index(): AnonymousResourceCollection
    {
        return InstitutionResource::collection(Institution::all());
    }
}
