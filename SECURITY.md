# Security

VNC Bind keeps each VM's saved VNC listen on the local console address and forwards only the addresses that were checked.

- State changes are POST with the Unraid `csrf_token` only. The status endpoint is read-only.
- A checked address must be an interface address on this host that the current settings allow. `0.0.0.0` and `::` are refused and never written.
- The forwarder re-checks the allowed addresses on every plan change; an address that is no longer allowed is closed.
- Each listener forwards to the VM console address and port from the running definition. It does not accept on an address of its own choosing.
- `virsh` runs without a shell, with the VM given as `--domain <uuid|name>`.
- Definitions are edited as XML (DOM), and are read with `--security-info` so the VNC password is kept.
- Interface Extra exclude lists are dropped when Use Interface Extra is Yes.
- Notifications list VM names and whether a VNC password is set. They do not include the password.
- Files under `/boot/config/plugins/VncBind` are on the FAT flash drive, which has no per-file permissions; anyone with flash access can read them. They do not hold VNC passwords.
- Known limits: see DOCS.md "Limits" (running VMs keep their socket; short correction window; browser question fails open).
- Uninstall moves VMs to their first checked address (never every interface) and leaves `/boot/config/plugins/VncBind` in place.
