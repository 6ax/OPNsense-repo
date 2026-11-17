{% if helpers.exists('OPNsense.kazuhahubwifiproxy.general.enabled') and OPNsense.kazuhahubwifiproxy.general.enabled == '1' %}
kazuhahub_wifiproxy_enable="YES"
{% else %}
kazuhahub_wifiproxy_enable="NO"
{% endif %}
