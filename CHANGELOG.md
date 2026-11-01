# Changelog

## 2026.11.01aa

- **The old alert schedule stops at once.** Versions before 2026.10.30aa wrote `/etc/cron.d/vncbind`,
  which Unraid's cron could not run (exit status 127 in the system log every five minutes). Updating
  removed the file, but cron keeps a removed file loaded until it is told or until its hourly rescan,
  so the errors went on for up to an hour. The update, the settings page and uninstall now tell cron
  through `/etc/cron.d/cron.update`.
- **Old package files are removed from the flash drive** after an update, as the other ibigsnet
  plugins do. Earlier updates left every previous `VncBind-*.txz` in `/boot/config/plugins/VncBind/`.
- **The install log ends with "install complete".** After `upgradepkg` the install printed nothing,
  so it looked stuck. It now checks the package landed, lists the installed package and says when it is done.

## 2026.10.30ab

- **Alert schedule on every boot device.** The cron file moved to `/boot/config/plugins/vncbind/` and
  `update_cron` runs again a minute after install. Unraid's `update_cron` reads `/boot/config/plugins/<plugin>/*.cron` only for plugins that have finished installing, and the folder name is case-sensitive on a ZFS boot device. Before, the five-minute check could be
  missing after a fresh install or on a ZFS boot device.

## 2026.10.30aa

- VM saves keep their VNC password. Definitions are read with `--security-info`; before, a form-view save through VNC Bind dropped the password, and alerts always reported "no password".
- New **Correct saved VMs** (default Yes): the daemon watches libvirt events and changes a saved VNC listen on every interface to the local console address as soon as it is saved, whatever saved it. A sweep every minute catches missed events. Running VMs keep their socket until restart; DOCS lists the remaining window.
- XML view saves now open the checked addresses (before, only the local console was set).
- The five-minute alert check and the Daily/Weekly check run again: the schedule is an Unraid cron file (`vncbind.cron` + `update_cron`). The old `/etc/cron.d/vncbind`, which cron could not run, is removed.
- Forwarding follows the current settings: an interface that is unchecked or excluded in Interface Extra closes its listeners. Checked addresses are stored per VM UUID; renamed VMs keep them, deleted VMs are cleaned up.
- Domain XML is edited with a real XML parser: self-closing VNC devices are seen, SPICE devices are never touched, network and socket listens are left alone.
- The forwarder reacts to VM start and stop at once (a stopped VM's port is freed before the next start), backs off a busy port instead of retrying every second, and limits connections (4 per client, 64 total) with idle and first-byte timeouts.
- Alert state and check results are written to the flash only when they change; a run is skipped when libvirt does not answer, so alerts do not repeat after every reboot.
- Uninstall moves each VM on the local console address to its first checked address (never every interface), removes the cron entry, and removes the plugin package correctly.
- The VM-save hook only loads on the VM pages and returns an object that supports `abort()` like a normal request.

## 2026.10.01af

- A checked interface is offered. Interface Extra no longer drops it for being outside the include list.

## 2026.10.01ae

- The VNC question is a checkbox list. Each checked address is opened. An internet address stays closed unless it is checked. The console on this server uses the local address.
- Use Interface Extra replaces Ignore Interface Extra. Yes is the default. The help links to Interface Extra.

## 2026.10.01ad

- The internet address list shows each address once. A bridge shim that repeats it is left out.

## 2026.10.01ac

- Settings lists the internet address alerting uses. Tailscale and private LAN addresses are left out of that list.

## 2026.10.01ab

- Alerting can be enabled or disabled. The install default is Enable.
- When this host has a public address, a VM whose saved or running VNC listen address is every interface raises an Unraid alert. A VNC password set on that graphics device lowers it to a warning.
- Tailscale interfaces, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10, and private IPv6 are not public addresses. The same VM is not notified again until the condition changes.

## 2026.10.01aa

- Ask which interface a VM VNC listen address uses before the template is saved.
- Settings live under User Utilities. VM Manager advanced view has the same Enable or Disable choice.
- Interface Extra include and exclude lists apply unless Ignore Interface Extra is Yes.
- A scheduled or manual check lists VMs whose VNC listen address is every interface. It does not edit them.
