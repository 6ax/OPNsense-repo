<div class="content-box">
    <div id="kzr_conflicts" class="alert alert-danger hidden" role="alert"></div>
    <div id="kzr_refused" class="alert alert-warning hidden" role="alert"></div>
    <table class="table table-condensed" id="kzr_status">
        <tbody>
            <tr>
                <td style="width: 16em;">{{ lang._('Service') }}</td>
                <td id="kzr_state">{{ lang._('Loading...') }}</td>
            </tr>
            <tr>
                <td>{{ lang._('Listening on') }}</td>
                <td id="kzr_listen"></td>
            </tr>
            <tr>
                <td>{{ lang._('Last configuration check') }}</td>
                <td>
                    <span id="kzr_check"></span>
                    <button type="button" class="btn btn-xs btn-default" id="kzr_check_now">
                        <i class="fa fa-check-circle-o fa-fw"></i> {{ lang._('Check now') }}
                    </button>
                    <pre id="kzr_check_message" class="hidden" style="margin-top: 0.5em;"></pre>
                </td>
            </tr>
        </tbody>
    </table>
    <div class="table-responsive">
        <table class="table table-striped table-condensed" id="kzr_homeservers">
            <thead>
                <tr>
                    <th>{{ lang._('Central server') }}</th>
                    <th>{{ lang._('Address') }}</th>
                    <th>{{ lang._('State') }}</th>
                    <th>{{ lang._('Outstanding requests') }}</th>
                </tr>
            </thead>
            <tbody id="kzr_homeserver_rows">
                <tr><td colspan="4">{{ lang._('Loading...') }}</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="content-box __mt">
    {{ partial("layout_partials/base_form", ['fields': generalForm, 'id': 'frm_general_settings']) }}
</div>

<div class="content-box __mt">
    <div id="CentralChangeMessage" class="alert alert-info" style="display: none;" role="alert">
        {{ lang._('After changing the central servers, use Apply to restart the service.') }}
    </div>
    <table id="grid-centrals" class="table table-condensed table-hover table-striped table-responsive"
           data-editDialog="DialogCentral" data-editAlert="CentralChangeMessage">
        <thead>
            <tr>
                <th data-column-id="uuid" data-type="string" data-identifier="true" data-visible="false">{{ lang._('ID') }}</th>
                <th data-column-id="enabled" data-width="6em" data-type="string" data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>
                <th data-column-id="sequence" data-width="7em" data-type="numeric">{{ lang._('Sequence') }}</th>
                <th data-column-id="description" data-type="string">{{ lang._('Description') }}</th>
                <th data-column-id="address" data-type="string">{{ lang._('Address') }}</th>
                <th data-column-id="port" data-width="7em" data-type="numeric">{{ lang._('Port') }}</th>
                <th data-column-id="commands" data-width="7em" data-formatter="commands" data-sortable="false">{{ lang._('Commands') }}</th>
            </tr>
        </thead>
        <tbody>
        </tbody>
        <tfoot>
            <tr>
                <td></td>
                <td>
                    <button data-action="add" type="button" class="btn btn-xs btn-primary"><span class="fa fa-fw fa-plus"></span></button>
                    <button data-action="deleteSelected" type="button" class="btn btn-xs btn-default"><span class="fa fa-fw fa-trash-o"></span></button>
                </td>
                <td colspan="5"></td>
            </tr>
        </tfoot>
    </table>
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/kazuhahubradius/service/reconfigure', 'data_service_widget': 'kazuhahubradius'}) }}
{{ partial("layout_partials/base_dialog", ['fields': formDialogCentral, 'id': 'DialogCentral', 'label': lang._('Edit central server')]) }}

<script>
$(function () {
    const stateLabels = {
        running: ["{{ lang._('Running') }}", 'label-success'],
        stopped: ["{{ lang._('Stopped') }}", 'label-danger'],
        disabled: ["{{ lang._('Disabled') }}", 'label-default'],
        unknown: ["{{ lang._('Unknown') }}", 'label-warning']
    };
    const serverLabels = {
        alive: ["{{ lang._('Alive') }}", 'label-success'],
        zombie: ["{{ lang._('Zombie: not answering, being probed') }}", 'label-warning'],
        dead: ["{{ lang._('Dead') }}", 'label-danger'],
        unknown: ["{{ lang._('Not used yet') }}", 'label-default']
    };
    const refusals = {
        'wan': "{{ lang._('is the WAN') }}",
        'gateway': "{{ lang._('has a gateway') }}",
        'wan-like': "{{ lang._('looks like a WAN') }}",
        'not-static': "{{ lang._('has no static IPv4 address') }}",
        'not-unicast': "{{ lang._('has no usable IPv4 address') }}",
        'disabled': "{{ lang._('is not enabled') }}",
        'missing': "{{ lang._('does not exist') }}",
        'invalid': "{{ lang._('is not valid') }}"
    };
    let timer = null;
    let centrals = [];

    function plain(value) {
        return $('<span/>').text(htmlDecode(value === null || value === undefined ? '' : String(value)));
    }

    function renderStatus(data) {
        const state = stateLabels[data.status] || stateLabels.unknown;
        $('#kzr_state').empty().append($('<span class="label label-opnsense"/>').addClass(state[1]).text(state[0]));
        const listen = $('#kzr_listen').empty();
        (data.listen || []).forEach(function (item) {
            listen.append($('<div/>').text(item.address + ':1812 (' + item.interface + ')'));
        });
        if (!(data.listen || []).length) {
            listen.text("{{ lang._('No usable interface selected') }}");
        }
        const conflicts = $('#kzr_conflicts').addClass('hidden').empty();
        if ((data.conflicts || []).length) {
            data.conflicts.forEach(function (text) {
                conflicts.append($('<div/>').text(text));
            });
            conflicts.removeClass('hidden');
        }
        const refused = $('#kzr_refused').addClass('hidden').empty();
        Object.keys(data.refused || {}).forEach(function (name) {
            refused.append($('<div/>').text(name + ' ' + (refusals[data.refused[name]] || '') + ": " +
                "{{ lang._('the service never listens there.') }}"));
        });
        if (refused.children().length) {
            refused.removeClass('hidden');
        }
        const check = data.check || {};
        const checkLabel = $('#kzr_check').empty();
        if (check.result === 'ok') {
            checkLabel.append($('<span class="label label-opnsense label-success"/>').text("{{ lang._('OK') }}"));
        } else if (check.result === 'failed') {
            checkLabel.append($('<span class="label label-opnsense label-danger"/>').text("{{ lang._('Failed') }}"));
        } else {
            checkLabel.append($('<span class="label label-opnsense label-default"/>').text("{{ lang._('Not run yet') }}"));
        }
        if (check.checked) {
            checkLabel.append(document.createTextNode(' ' + new Date(check.checked * 1000).toLocaleString() + ' '));
        }
        const message = $('#kzr_check_message').addClass('hidden').empty();
        if (check.result === 'failed' && check.message) {
            message.text(htmlDecode(check.message)).removeClass('hidden');
        }
    }

    function renderServers(data) {
        const rows = $('#kzr_homeserver_rows').empty();
        const servers = (data && data.servers) || [];
        if (!servers.length) {
            const text = data && data.status === 'ok' ? "{{ lang._('No central server is configured.') }}"
                : "{{ lang._('The service is not running.') }}";
            rows.append($('<tr/>').append($('<td colspan="4"/>').text(text)));
            return;
        }
        servers.forEach(function (server) {
            const label = serverLabels[server.state] || serverLabels.unknown;
            let name = '';
            centrals.forEach(function (row) {
                if (row.address === server.address && String(row.port) === String(server.port)) {
                    name = row.description || '';
                }
            });
            rows.append($('<tr/>')
                .append($('<td/>').append(plain(name)))
                .append($('<td/>').text(server.address + ':' + server.port))
                .append($('<td/>').append($('<span class="label label-opnsense"/>').addClass(label[1]).text(label[0])))
                .append($('<td/>').text(server.outstanding)));
        });
    }

    function refresh(delay) {
        clearTimeout(timer);
        timer = setTimeout(function () {
            ajaxGet('/api/kazuhahubradius/settings/searchCentral', {}, function (list) {
                centrals = (list && list.rows) || [];
                ajaxGet('/api/kazuhahubradius/service/status', {}, renderStatus);
                ajaxGet('/api/kazuhahubradius/service/homeservers', {}, renderServers);
                refresh(15000);
            });
        }, delay || 0);
    }

    mapDataToFormUI({'frm_general_settings': '/api/kazuhahubradius/settings/get'}).done(function () {
        formatTokenizersUI();
        $('.selectpicker').selectpicker('refresh');
        updateServiceControlUI('kazuhahubradius');
    });

    $('#grid-centrals').UIBootgrid({
        search: '/api/kazuhahubradius/settings/searchCentral/',
        get: '/api/kazuhahubradius/settings/getCentral/',
        set: '/api/kazuhahubradius/settings/setCentral/',
        add: '/api/kazuhahubradius/settings/addCentral/',
        del: '/api/kazuhahubradius/settings/delCentral/',
        toggle: '/api/kazuhahubradius/settings/toggleCentral/'
    });

    $('#kzr_check_now').click(function () {
        const button = $(this).prop('disabled', true);
        ajaxCall('/api/kazuhahubradius/service/check', {}, function () {
            button.prop('disabled', false);
            refresh(0);
        });
    });

    $('#reconfigureAct').SimpleActionButton({
        onPreAction: function () {
            const done = new $.Deferred();
            saveFormToEndpoint('/api/kazuhahubradius/settings/set', 'frm_general_settings', function () {
                done.resolve();
            }, false, function () {
                done.reject();
            });
            return done;
        },
        onAction: function () {
            refresh(2000);
        }
    });

    refresh(0);
});
</script>
