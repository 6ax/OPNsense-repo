export default class KazuhaHubWifiProxy extends BaseTableWidget {
    constructor() {
        super();
    }

    getMarkup() {
        let $container = $('<div></div>');
        let $table = this.createTable('kazuhahubwifiproxyTable', {
            headerPosition: 'left',
            headers: [
                this.translations.service,
                this.translations.listen,
                this.translations.check,
                this.translations.central
            ]
        });
        $container.append($table);
        return $container;
    }

    async onWidgetTick() {
        const status = await this.ajaxCall('/api/kazuhahubwifiproxy/service/status');
        if (!status || !status.status) {
            $('#kazuhahubwifiproxyTable').empty().append(
                $('<div class="error-message"></div>').text(this.translations.unknown)
            );
            return;
        }
        const servers = await this.ajaxCall('/api/kazuhahubwifiproxy/service/homeservers');
        const data = {status: status, servers: (servers && servers.servers) || []};
        if (!this.dataChanged('kazuhahubwifiproxy', data)) {
            return;
        }
        super.updateTable('kazuhahubwifiproxyTable', [[
            this.serviceCell(status),
            this.listenCell(status),
            this.checkCell(status),
            this.serversCell(data.servers)
        ]]);
    }

    text(value) {
        return $('<span></span>').text(value).prop('outerHTML');
    }

    serviceCell(status) {
        const colors = {running: 'text-success', stopped: 'text-danger', disabled: 'text-muted'};
        const label = this.translations[status.status] || this.translations.unknown;
        const color = colors[status.status] || 'text-warning';
        const $cell = $('<div></div>').append(
            $('<i class="fa fa-circle"></i>').addClass(color),
            document.createTextNode(' '),
            $('<a href="/ui/kazuhahubwifiproxy/" target="_blank"></a>').text(label)
        );
        (status.conflicts || []).forEach(conflict => {
            $cell.append($('<div class="text-danger"></div>').text(conflict));
        });
        return $cell.prop('outerHTML');
    }

    listenCell(status) {
        const addresses = (status.listen || []).map(item => item.address + ':1812');
        return this.text(addresses.length ? addresses.join(', ') : this.translations.none);
    }

    checkCell(status) {
        const check = status.check || {};
        const known = {ok: this.translations.ok, failed: this.translations.failed};
        return this.text(known[check.result] || this.translations.notrun);
    }

    serversCell(servers) {
        if (!servers.length) {
            return this.text(this.translations.none);
        }
        const colors = {alive: 'text-success', zombie: 'text-warning', dead: 'text-danger', unknown: 'text-muted'};
        const labels = {
            alive: this.translations.alive, zombie: this.translations.zombie,
            dead: this.translations.dead, unknown: this.translations.unused
        };
        const $cell = $('<div></div>');
        servers.forEach(server => {
            $cell.append($('<div></div>').append(
                $('<i class="fa fa-circle"></i>').addClass(colors[server.state] || 'text-muted'),
                document.createTextNode(' ' + server.address + ':' + server.port + ' (' +
                    (labels[server.state] || server.state) + ')')
            ));
        });
        return $cell.prop('outerHTML');
    }
}
