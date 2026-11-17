{% if helpers.exists('OPNsense.kazuhahubradius.general.enabled') and OPNsense.kazuhahubradius.general.enabled == '1' %}
kazuhahub_radiusd_enable="YES"
{% else %}
kazuhahub_radiusd_enable="NO"
{% endif %}
