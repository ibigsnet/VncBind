# Security

VNC Bind keeps the VM console socket on the local address and opens a listener for each address that was checked.

- State changes are POST with the Unraid `csrf_token` only.
- A checked address must be an interface address from this host. `0.0.0.0` and `::` are refused. An address that was not checked is not bound.
- Each listener forwards to the VM console socket. It does not accept on an address of its own choosing.
- Domain names passed to `virsh` are one argument. Control characters are refused.
- Interface Extra exclude lists are dropped when Use Interface Extra is Yes.
- Suggestions and notifications list VM names and whether a VNC password is set. They do not include the password.
- Uninstall leaves `/boot/config/plugins/VncBind` in place.
