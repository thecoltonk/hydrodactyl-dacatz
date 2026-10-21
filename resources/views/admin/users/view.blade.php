@extends('layouts.admin')

@section('title')
    Manage User: {{ $user->username }}
@endsection

@section('content-header')
    <h1>{{ $user->name_first }} {{ $user->name_last}}<small>{{ $user->username }}</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.users') }}">Users</a></li>
        <li class="active">{{ $user->username }}</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <form action="{{ route('admin.users.view', $user->id) }}" method="post">
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Identity</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label for="email" class="control-label">Email</label>
                        <div>
                            <input type="email" name="email" value="{{ $user->email }}" class="form-control form-autocomplete-stop">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="registered" class="control-label">Username</label>
                        <div>
                            <input type="text" name="username" value="{{ $user->username }}" class="form-control form-autocomplete-stop">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="registered" class="control-label">Client First Name</label>
                        <div>
                            <input type="text" name="name_first" value="{{ $user->name_first }}" class="form-control form-autocomplete-stop">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="registered" class="control-label">Client Last Name</label>
                        <div>
                            <input type="text" name="name_last" value="{{ $user->name_last }}" class="form-control form-autocomplete-stop">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Default Language</label>
                        <div>
                            <select name="language" class="form-control">
                                @foreach($languages as $key => $value)
                                    <option value="{{ $key }}" @if($user->language === $key) selected @endif>{{ $value }}</option>
                                @endforeach
                            </select>
                            <p class="text-muted"><small>The default language to use when rendering the Panel for this user.</small></p>
                        </div>
                    </div>
                </div>
                <div class="box-footer">
                    {!! csrf_field() !!}
                    {!! method_field('PATCH') !!}
                    <input type="submit" value="Update User" class="btn btn-primary btn-sm">
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Password</h3>
                </div>
                <div class="box-body">
                    <div class="alert alert-success" style="display:none;margin-bottom:10px;" id="gen_pass"></div>
                    <div class="form-group no-margin-bottom">
                        <label for="password" class="control-label">Password <span class="field-optional"></span></label>
                        <div>
                            <input type="password" id="password" name="password" class="form-control form-autocomplete-stop">
                            <p class="text-muted small">Leave blank to keep this user's password the same. User will not receive any notification if password is changed.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Permissions</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label for="root_admin" class="control-label">Administrator</label>
                        <div>
                            <select name="root_admin" class="form-control">
                                <option value="0">@lang('strings.no')</option>
                                <option value="1" {{ $user->root_admin ? 'selected="selected"' : '' }}>@lang('strings.yes')</option>
                            </select>
                            <p class="text-muted"><small>Setting this to 'Yes' gives a user full administrative access.</small></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
    @php
        $plan = \Pterodactyl\Models\UserPlan::query()->where('user_id', $user->id)->first();
        $planUsed = app(\Pterodactyl\Services\SelfService\PlanService::class)->usage($user);
        $gb = fn ($mib) => rtrim(rtrim(number_format($mib / 1024, 2), '0'), '.');
    @endphp
    <div class="col-xs-12">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Server Plan</h3>
            </div>
            <form action="{{ route('admin.users.plan', $user->id) }}" method="POST">
                {!! csrf_field() !!}
                {!! method_field('PATCH') !!}
                <div class="box-body">
                    <p class="text-muted"><small>
                        With a plan, this user can create, resize and delete their own servers from the panel, as long as
                        the total across all servers they own stays within these limits. Currently using
                        <strong>{{ $gb($planUsed['memory']) }} GB RAM</strong>,
                        <strong>{{ rtrim(rtrim(number_format($planUsed['cpu'] / 100, 2), '0'), '.') }} cores</strong>,
                        <strong>{{ $gb($planUsed['disk']) }} GB storage</strong> across
                        <strong>{{ $planUsed['servers'] }} server(s)</strong>.
                    </small></p>
                    <div class="row">
                        <div class="form-group col-md-2">
                            <label class="control-label">Self-service</label>
                            <select name="plan_enabled" class="form-control">
                                <option value="0">Off</option>
                                <option value="1" {{ $plan ? 'selected="selected"' : '' }}>On</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label class="control-label">Total RAM (GB)</label>
                            <input type="number" step="0.5" min="0.5" name="plan_memory_gb" class="form-control" value="{{ $plan ? $gb($plan->memory) : '' }}" placeholder="16">
                        </div>
                        <div class="form-group col-md-2">
                            <label class="control-label">Total CPU cores</label>
                            <input type="number" step="0.5" min="0.5" name="plan_cpu_cores" class="form-control" value="{{ $plan ? $plan->cpu / 100 : '' }}" placeholder="4">
                        </div>
                        <div class="form-group col-md-3">
                            <label class="control-label">Total storage (GB)</label>
                            <input type="number" step="1" min="1" name="plan_disk_gb" class="form-control" value="{{ $plan ? $gb($plan->disk) : '' }}" placeholder="100">
                        </div>
                        <div class="form-group col-md-2">
                            <label class="control-label">Max servers</label>
                            <input type="number" step="1" min="0" name="plan_servers" class="form-control" value="{{ $plan ? $plan->servers : 0 }}">
                            <p class="text-muted"><small>0 = no limit</small></p>
                        </div>
                    </div>
                </div>
                <div class="box-footer">
                    <input type="submit" value="Save Plan" class="btn btn-success btn-sm">
                </div>
            </form>
        </div>
    </div>
    <div class="col-xs-12">
        <div class="box box-danger">
            <div class="box-header with-border">
                <h3 class="box-title">Delete User</h3>
            </div>
            <div class="box-body">
                <p class="no-margin">There must be no servers associated with this account in order for it to be deleted.</p>
            </div>
            <div class="box-footer">
                <form action="{{ route('admin.users.view', $user->id) }}" method="POST">
                    {!! csrf_field() !!}
                    {!! method_field('DELETE') !!}
                    <input id="delete" type="submit" class="btn btn-sm btn-danger pull-right" {{ $user->servers->count() < 1 ?: 'disabled' }} value="Delete User" />
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
