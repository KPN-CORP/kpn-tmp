<?php

namespace App\Http\Controllers;

use App\Models\JobStatus;
use App\Http\Requests\StoreJobStatusRequest;
use App\Http\Requests\UpdateJobStatusRequest;

class JobStatusController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreJobStatusRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(JobStatus $jobStatus)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(JobStatus $jobStatus)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateJobStatusRequest $request, JobStatus $jobStatus)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(JobStatus $jobStatus)
    {
        //
    }
}
