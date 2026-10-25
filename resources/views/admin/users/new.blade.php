@extends('layouts.admin')

@section('title')
    Create User
@endsection

@section('content-header')
    <h1>Create User<small>Add a new user to the system.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.users') }}">Users</a></li>
        <li class="active">Create</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <form method="post">
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Identity</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label for="email" class="control-label">Email</label>
                        <div>
                            <input type="text" autocomplete="off" name="email" value="{{ old('email') }}" class="form-control" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="username" class="control-label">Username</label>
                        <div>
                            <input type="text" autocomplete="off" name="username" value="{{ old('username') }}" class="form-control" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="name_first" class="control-label">Client First Name</label>
                        <div>
                            <input type="text" autocomplete="off" name="name_first" value="{{ old('name_first') }}" class="form-control" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="name_last" class="control-label">Client Last Name</label>
                        <div>
                            <input type="text" autocomplete="off" name="name_last" value="{{ old('name_last') }}" class="form-control" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Default Language</label>
                        <div>
                            <select name="language" class="form-control">
                                @foreach($languages as $key => $value)
                                    <option value="{{ $key }}" @if(config('app.locale') === $key) selected @endif>{{ $value }}</option>
                                @endforeach
                            </select>
                            <p class="text-muted"><small>The default language to use when rendering the Panel for this user.</small></p>
                        </div>
                    </div>
                </div>
                <div class="box-footer">
                    {!! csrf_field() !!}
                    <input type="submit" value="Create User" class="btn btn-success btn-sm">
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Permissions</h3>
                </div>
                <div class="box-body">
                    <div class="form-group col-md-12">
                        <label for="root_admin" class="control-label">Administrator</label>
                        <div>
                            <select name="root_admin" class="form-control">
                                <option value="0">@lang('strings.no')</option>
                                <option value="1">@lang('strings.yes')</option>
                            </select>
                            <p class="text-muted"><small>Setting this to 'Yes' gives a user full administrative access.</small></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Password</h3>
                </div>
                <div class="box-body">
                    <div class="alert alert-info">
                        <p>A password is required: this panel doesn't send emails, so new users can't set their own. Give them this password and have them change it under Account after signing in.</p>
                    </div>
                    <div id="gen_pass" class=" alert alert-success" style="display:none;margin-bottom: 10px;"></div>
                    <div class="form-group">
                        <label for="pass" class="control-label">Password</label>
                        <div>
                            <input type="password" name="password" class="form-control" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-12">
            <div class="box box-success">
                <div class="box-header with-border">
                    <h3 class="box-title">Server Plan</h3>
                </div>
                <div class="box-body">
                    <p class="text-muted"><small>
                        Optional. With a plan, this user can create, resize and delete their own servers from the panel,
                        as long as the total across all their servers stays within these limits. You can change it later
                        on the user's page.
                    </small></p>
                    <div class="row">
                        <div class="form-group col-md-2">
                            <label class="control-label">Self-service</label>
                            <select name="plan_enabled" class="form-control">
                                <option value="0">Off</option>
                                <option value="1" {{ old('plan_enabled') ? 'selected="selected"' : '' }}>On</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label class="control-label">Total RAM (GB)</label>
                            <input type="number" step="0.5" min="0.5" name="plan_memory_gb" class="form-control" value="{{ old('plan_memory_gb') }}" placeholder="16">
                        </div>
                        <div class="form-group col-md-2">
                            <label class="control-label">Total CPU cores</label>
                            <input type="number" step="0.5" min="0.5" name="plan_cpu_cores" class="form-control" value="{{ old('plan_cpu_cores') }}" placeholder="4">
                        </div>
                        <div class="form-group col-md-3">
                            <label class="control-label">Total storage (GB)</label>
                            <input type="number" step="1" min="1" name="plan_disk_gb" class="form-control" value="{{ old('plan_disk_gb') }}" placeholder="100">
                        </div>
                        <div class="form-group col-md-2">
                            <label class="control-label">Max servers</label>
                            <input type="number" step="1" min="0" name="plan_servers" class="form-control" value="{{ old('plan_servers', 0) }}">
                            <p class="text-muted"><small>0 = no limit</small></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@section('footer-scripts')
    @parent
    <script>$("#gen_pass_bttn").click(function (event) {
            event.preventDefault();
            $.ajax({
                type: "GET",
                url: "/password-gen/12",
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
               },
                success: function(data) {
                    $("#gen_pass").html('<strong>Generated Password:</strong> ' + data).slideDown();
                    $('input[name="password"], input[name="password_confirmation"]').val(data);
                    return false;
                }
            });
            return false;
        });
    </script>
@endsection
