<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers;

use Illuminate\Http\Request;

final class ResturantsController
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('resturants::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('resturants::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {}

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('resturants::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('resturants::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id) {}
}
