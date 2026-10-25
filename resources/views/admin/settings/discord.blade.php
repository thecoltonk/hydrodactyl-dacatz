@extends('layouts.admin')
@include('partials/admin.settings.nav', ['activeTab' => 'discord'])

@section('title')
  Discord Settings
@endsection

@section('content-header')
  <h1>Discord<small>Bot for the staff feed, account linking and DMs.</small></h1>
  <ol class="breadcrumb">
    <li><a href="{{ route('admin.index') }}">Admin</a></li>
    <li class="active">Settings</li>
  </ol>
@endsection

@section('content')
  @yield('settings::nav')
  <div class="row">
    <div class="col-xs-12">
      <form action="{{ route('admin.settings.discord') }}" method="POST" autocomplete="off">
        <div class="box">
          <div class="box-header with-border">
            <h3 class="box-title">Bot</h3>
          </div>
          <div class="box-body">
            <div class="row">
              <div class="form-group col-md-4">
                <label class="control-label">Status</label>
                <select name="enabled" class="form-control">
                  <option value="false" @if(!config('hydrodactyl.discord.enabled')) selected @endif>Off</option>
                  <option value="true" @if(config('hydrodactyl.discord.enabled')) selected @endif>On</option>
                </select>
                <p class="text-muted small">Nothing is sent while this is off.</p>
              </div>
              <div class="form-group col-md-4">
                <label class="control-label">Application ID</label>
                <input type="text" class="form-control" name="application_id" inputmode="numeric"
                  value="{{ old('application_id', config('hydrodactyl.discord.application_id')) }}" />
                <p class="text-muted small">Developer Portal → General Information.</p>
              </div>
              <div class="form-group col-md-4">
                <label class="control-label">Public Key</label>
                <input type="text" class="form-control" name="public_key"
                  value="{{ old('public_key', config('hydrodactyl.discord.public_key')) }}" />
                <p class="text-muted small">Same page. Used later for slash commands.</p>
              </div>
            </div>
            <div class="row">
              <div class="form-group col-md-6">
                <label class="control-label">Bot Token</label>
                <input type="password" class="form-control" name="bot_token" autocomplete="new-password"
                  placeholder="{{ $hasToken ? 'Saved. Leave blank to keep it.' : 'Bot tab → Reset Token, then paste here' }}" />
                @if($hasToken)
                  <label class="small text-muted" style="font-weight: normal; margin-top: 4px;">
                    <input type="checkbox" name="clear_bot_token" value="1" /> Remove the saved token
                  </label>
                @endif
                <p class="text-muted small">Stored encrypted and never shown again.</p>
              </div>
              <div class="form-group col-md-6">
                <label class="control-label">OAuth2 Client Secret</label>
                <input type="password" class="form-control" name="client_secret" autocomplete="new-password"
                  placeholder="{{ $hasSecret ? 'Saved. Leave blank to keep it.' : 'OAuth2 tab → Reset Secret, then paste here' }}" />
                @if($hasSecret)
                  <label class="small text-muted" style="font-weight: normal; margin-top: 4px;">
                    <input type="checkbox" name="clear_client_secret" value="1" /> Remove the saved secret
                  </label>
                @endif
                <p class="text-muted small">Needed for users to link their Discord account.</p>
              </div>
            </div>
          </div>
        </div>

        <div class="box">
          <div class="box-header with-border">
            <h3 class="box-title">Feed &amp; linking</h3>
          </div>
          <div class="box-body">
            <div class="row">
              <div class="form-group col-md-6">
                <label class="control-label">Feed Channel ID</label>
                <input type="text" class="form-control" name="channel_id" inputmode="numeric"
                  value="{{ old('channel_id', config('hydrodactyl.discord.channel_id')) }}" />
                <p class="text-muted small">Server created/deleted/resized, install and backup failures, and security events. No pings, and no IPs or emails, so it's fine in a channel non-admins can read.</p>
              </div>
              <div class="form-group col-md-6">
                <label class="control-label">Discord Server Invite <span class="field-optional"></span></label>
                <input type="text" class="form-control" name="invite_url" placeholder="https://discord.gg/..."
                  value="{{ old('invite_url', config('hydrodactyl.discord.invite_url')) }}" />
                <p class="text-muted small">Shown on the account page. The bot can only DM people who share a server with it.</p>
              </div>
            </div>
            <div class="row">
              <div class="col-md-12">
                <div class="alert alert-info" style="margin-bottom: 0;">
                  <strong>Developer Portal setup</strong>
                  <ol style="margin-bottom: 0;">
                    <li>OAuth2 → Redirects: add <code>{{ $redirectUrl }}</code></li>
                    <li>Bot tab: turn off Public Bot; leave the privileged intents off.</li>
                    @if($inviteUrl)
                      <li>Invite the bot: <a href="{{ $inviteUrl }}" target="_blank" rel="noopener">{{ $inviteUrl }}</a></li>
                    @else
                      <li>Save the Application ID to get an invite link here.</li>
                    @endif
                  </ol>
                  <p style="margin: 8px 0 0;">{{ $linkedUsers }} panel {{ $linkedUsers === 1 ? 'account has' : 'accounts have' }} linked Discord.</p>
                </div>
              </div>
            </div>
          </div>
          <div class="box-footer">
            {{ csrf_field() }}
            <button type="submit" name="_method" value="PATCH" class="btn btn-sm btn-primary pull-right">Save</button>
          </div>
        </div>
      </form>

      <form action="{{ route('admin.settings.discord.test') }}" method="POST">
        {{ csrf_field() }}
        <button type="submit" class="btn btn-sm btn-default">Send test message to the feed channel</button>
      </form>
    </div>
  </div>
@endsection
