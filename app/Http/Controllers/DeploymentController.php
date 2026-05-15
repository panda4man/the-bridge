<?php

namespace App\Http\Controllers;

use App\Models\Deployment;

class DeploymentController extends Controller
{
    public function show(Deployment $deployment)
    {
        return view('deployments.show', compact('deployment'));
    }

    public function stream(Deployment $deployment)
    {
        // Implemented in Task 8
    }
}
