{% if helpers.exists('OPNsense.kazuhahubwifiradiusproxy.general.enabled') and OPNsense.kazuhahubwifiradiusproxy.general.enabled == '1' %}
kazuhahub_wifiradiusproxy_enable="YES"
{% else %}
kazuhahub_wifiradiusproxy_enable="NO"
{% endif %}
