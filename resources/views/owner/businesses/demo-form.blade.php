@extends('layouts.fleet')

@section('title', 'New demo business')

@section('content')
@include('layouts.partials.page-header', [
    'title' => 'New demo for a client',
    'subtitle' => 'The client signs in with these details and chooses a guided tour or explores alone',
    'backUrl' => route('owner.businesses.index'),
    'breadcrumbs' => [
        ['label' => 'Owner', 'url' => route('owner.dashboard'), 'icon' => 'fa-home'],
        ['label' => 'Businesses', 'url' => route('owner.businesses.index')],
        ['label' => 'New demo'],
    ],
])

<div class="sx-box">
    <div class="sx-box-body">
        <p>This creates a separate demo business with sample products, customers, a sale and an expense. It does not use another customer's data. After you save, copy the login details and send them to the client. The password is shown only once.</p>
        <form method="post" action="{{ route('owner.businesses.demo.store') }}" class="sx-form">
            @csrf
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="sx-req">Business name*</label>
                        <input type="text" name="name" class="form-control" required value="{{ old('name') }}" placeholder="Acme Traders Demo">
                    </div>
                    <div class="form-group">
                        <label class="sx-req">Client name*</label>
                        <input type="text" name="client_name" class="form-control" required value="{{ old('client_name') }}">
                    </div>
                    <div class="form-group">
                        <label>Client email</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email') }}">
                        <p class="help-block">Optional. Leave blank if you only want to send a username.</p>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="phone" class="form-control" value="{{ old('phone') }}">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="sx-req">Username*</label>
                        <input type="text" name="username" class="form-control" required value="{{ old('username') }}" placeholder="acme.demo">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="text" name="password" class="form-control" value="{{ old('password') }}" autocomplete="off">
                        <p class="help-block">Leave blank to generate a password you can copy after saving.</p>
                    </div>
                    <div class="form-group">
                        <label>Confirm password</label>
                        <input type="text" name="password_confirmation" class="form-control" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="sx-req">Access for (days)*</label>
                        <input type="number" name="days" class="form-control" required min="1" max="365" value="{{ old('days', 30) }}">
                    </div>
                </div>
            </div>
            <div class="sx-form-actions">
                <button type="submit" class="btn btn-success">Create demo</button>
                <a href="{{ route('owner.businesses.index') }}" class="btn btn-danger">Close</a>
            </div>
        </form>
    </div>
</div>
@endsection
