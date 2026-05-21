<div class="panel">
  <h3><i class="icon-shield"></i> {$panel_title|escape:'html':'UTF-8'}</h3>
  <p>{l s='Configure observe, rate-limit or blocking behavior per shop context.' d='Modules.Advclickfraud.Admin'}</p>
</div>

<ul class="nav nav-tabs" id="advclickfraud-dashboard-tabs">
  <li class="active"><a href="#advclickfraud-tab-analytics" data-toggle="tab"><i class="icon-bar-chart"></i> {l s='Analytics and reason codes' d='Modules.Advclickfraud.Admin'}</a></li>
  <li><a href="#advclickfraud-tab-settings" data-toggle="tab"><i class="icon-cogs"></i> {l s='Detection settings' d='Modules.Advclickfraud.Admin'}</a></li>
  <li><a href="#advclickfraud-tab-integrations" data-toggle="tab"><i class="icon-link"></i> {l s='Network integrations' d='Modules.Advclickfraud.Admin'}</a></li>
</ul>

<div class="tab-content" style="padding-top:20px;">
  <div class="tab-pane active" id="advclickfraud-tab-analytics">
    <div class="row">
      <div class="col-lg-3"><div class="panel"><h4>{l s='Total events' d='Modules.Advclickfraud.Admin'}</h4><strong>{$stats.total_events|intval}</strong></div></div>
      <div class="col-lg-3"><div class="panel"><h4>{l s='High-risk events' d='Modules.Advclickfraud.Admin'}</h4><strong>{$stats.high_risk_events|intval}</strong></div></div>
      <div class="col-lg-3"><div class="panel"><h4>{l s='Client fingerprints' d='Modules.Advclickfraud.Admin'}</h4><strong>{$stats.client_fingerprints|intval}</strong></div></div>
      <div class="col-lg-3"><div class="panel"><h4>{l s='Network fingerprints' d='Modules.Advclickfraud.Admin'}</h4><strong>{$stats.network_fingerprints|intval}</strong></div></div>
    </div>

    <div class="panel">
      <div class="panel-heading"><i class="icon-refresh"></i> {l s='Table refresh' d='Modules.Advclickfraud.Admin'}</div>
      <div class="form-inline">
        <label for="advclickfraud-refresh-interval" style="margin-right:8px;">{l s='Refresh interval' d='Modules.Advclickfraud.Admin'}</label>
        <select id="advclickfraud-refresh-interval" class="form-control fixed-width-md" data-disabled-label="{$admin_refresh_disabled_label|escape:'html':'UTF-8'}">
          <option value="0" {if $admin_refresh_interval == 0}selected="selected"{/if}>{l s='Disabled' d='Modules.Advclickfraud.Admin'}</option>
          <option value="15" {if $admin_refresh_interval == 15}selected="selected"{/if}>15s</option>
          <option value="30" {if $admin_refresh_interval == 30}selected="selected"{/if}>30s</option>
          <option value="60" {if $admin_refresh_interval == 60}selected="selected"{/if}>60s</option>
          <option value="120" {if $admin_refresh_interval == 120}selected="selected"{/if}>120s</option>
        </select>
        <span style="margin-left:15px;">{l s='Next refresh' d='Modules.Advclickfraud.Admin'}: <strong id="advclickfraud-refresh-countdown">{l s='Disabled' d='Modules.Advclickfraud.Admin'}</strong></span>
      </div>
    </div>

    <div class="panel" id="advclickfraud-events-table-wrapper">
      <div class="panel-heading"><i class="icon-list"></i> {l s='Recent risk events' d='Modules.Advclickfraud.Admin'}</div>
      <div class="table-responsive">
        <table class="table table-bordered">
          <thead>
            <tr>
              <th>{l s='Date' d='Modules.Advclickfraud.Admin'}</th>
              <th>{l s='Event type' d='Modules.Advclickfraud.Admin'}</th>
              <th>{l s='Risk' d='Modules.Advclickfraud.Admin'}</th>
              <th>{l s='Decision' d='Modules.Advclickfraud.Admin'}</th>
              <th>{l s='Channel' d='Modules.Advclickfraud.Admin'}</th>
              <th>{l s='Campaign' d='Modules.Advclickfraud.Admin'}</th>
              <th>{l s='Reason codes' d='Modules.Advclickfraud.Admin'}</th>
            </tr>
          </thead>
          <tbody>
          {if $recent_events}
            {foreach from=$recent_events item=event}
              <tr>
                <td>{$event.date_add|escape:'html':'UTF-8'}</td>
                <td>{$event.event_type|escape:'html':'UTF-8'}</td>
                <td>{$event.risk_score|intval}</td>
                <td>{$event.decision|escape:'html':'UTF-8'}</td>
                <td>{$event.channel|escape:'html':'UTF-8'}</td>
                <td>{$event.campaign|escape:'html':'UTF-8'}</td>
                <td>{$event.reason_list|escape:'html':'UTF-8'}</td>
              </tr>
            {/foreach}
          {else}
            <tr><td colspan="7" class="text-center">{l s='No events have been recorded yet.' d='Modules.Advclickfraud.Admin'}</td></tr>
          {/if}
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="tab-pane" id="advclickfraud-tab-settings">{$configuration_form}</div>

  <div class="tab-pane" id="advclickfraud-tab-integrations">
    <div class="panel">
      <div class="panel-heading"><i class="icon-link"></i> {l s='JA4 and trusted proxy integration' d='Modules.Advclickfraud.Admin'}</div>
      <p>{l s='JA4 and JA4H headers are accepted only when the request comes from a trusted proxy IP address configured in Detection settings.' d='Modules.Advclickfraud.Admin'}</p>
      <p>{l s='Each trusted proxy line can include an optional note after a hash sign.' d='Modules.Advclickfraud.Admin'}</p>
      <pre>203.0.113.10 # Cloudflare edge
198.51.100.10 # HAProxy node 1</pre>
    </div>
  </div>
</div>

<div class="panel"><h3><i class="icon-book"></i> {$manual_title|escape:'html':'UTF-8'}</h3><p>{$manual_help|escape:'html':'UTF-8'}</p></div>
