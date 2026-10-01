# Security

VNC Bind rewrites a VM graphics listen address. It does not open a new listener of its own.

- State changes are POST with the Unraid `csrf_token` only.
- The listen address must be an interface address from this host. `0.0.0.0` and `::` are refused.
- Domain names passed to `virsh` are one argument. Control characters are refused.
- Interface Extra exclude lists are dropped unless Ignore Interface Extra is Yes.
- Suggestions and notifications list VM names and whether a VNC password is set. They do not include the password.
- Uninstall leaves `/boot/config/plugins/VncBind` in place.
