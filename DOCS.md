# VNC Bind

A VM template save in Virtual Machines writes the VNC listen address. The form builder uses every interface (`0.0.0.0`). On a server with an internet address, that publishes the console. VNC passwords are at most 8 characters, so the listen address is the control this plugin keeps.

## How it works

- Each VM's saved VNC listen is the local console address `127.0.0.1`. The VM Manager web console keeps working.
- For each address you check, a small forwarder on this server listens on that address and the VM's VNC port and passes connections to the console. Nothing else is opened.
- The VNC password (`passwd`) is kept on every change the plugin makes.

## Settings

Settings → User Utilities → VNC Bind. The Interjection choice is also in Settings → VM Manager, advanced view.

- **Interjection** — Enable asks, before a VM with VNC is created or updated, which addresses VNC should open. Disable leaves the save alone.
- **Correct saved VMs** — Yes (default): when libvirt saves a VM whose VNC listen is every interface, the daemon changes the saved listen to `127.0.0.1` right away, whatever saved it (form view, XML view, a template, `virsh`). It also checks every VM once a minute in case an event was missed. Network- and socket-type listens are left alone.
- **Use Interface Extra** — Yes, the default, follows Settings → Network Settings → [Interface Extra](/Settings/NetworkExtra): included interfaces are added to `br0`, `bond0`, `eth0` and `wlan0`, excluded interfaces are dropped. No uses only the checked interfaces on this page.
- **Allowed interfaces** — interfaces that appear in the question. None checked offers the set above. A checked interface is offered even when it is outside that set; an Interface Extra exclude is still dropped. The question can open more than one address. `0.0.0.0` is never written. An internet address stays closed unless it is checked.
- **Alerting** — Enable, the default, sends an Unraid notification when this host has an internet address and a saved or running VNC listen is every interface (`0.0.0.0` or `::`). No VNC password is an alert; a password set is a warning. The same VM is not notified again until that condition changes. Apply, Check now, a VM save, and a check every five minutes (Unraid cron) run it. When libvirt is not running the check is skipped.
- **Internet address** — addresses alerting treats as reachable from the internet. Tailscale and private ranges are left out: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10`, IPv6 unique-local and link-local. A bridge shim that repeats an address is listed once.
- **Template check** — Off, Daily, or Weekly. Lists VMs whose saved or running VNC listen is every interface. It does not edit them. Check now runs it once.

## What is checked, and when

- Forwarding follows the current settings: unchecking an interface, excluding it in Interface Extra, or removing the address from the host closes its listeners within seconds.
- The checked addresses are stored per VM UUID in `/boot/config/plugins/VncBind/binds.json`, so a renamed VM keeps them and a new VM with an old name does not inherit them. Entries for deleted VMs are removed.
- When a VM stops, its listeners close at once, so the port is free when a VM starts again.
- Each forwarded address accepts at most 4 connections per client address and 64 in total. A connection that sends nothing for 60 s after connecting, or has no traffic for 15 minutes, is closed.

## Limits (be aware)

- **Running VMs keep their socket.** A change to the saved listen applies at the next VM start. If VM Manager starts the VM right after creating it ("Start VM after creation"), that run listens on every interface until the VM restarts. Alerting reports it.
- **Short window after a save.** Between libvirt saving `0.0.0.0` and the daemon's correction there is a short delay (normally well under a second; up to a minute if the event stream is down, for example while libvirt restarts). The saved definition is never started by the plugin in that window.
- **The browser question fails open.** If the question cannot load (plugin settings unreachable, script error, tab closed), the save goes ahead unchanged. Correct saved VMs and Alerting still apply.
- Saving a reusable template from form view still stores the address VM Manager writes; the VM made from it is corrected when it is saved.

## Uninstall

Remove from Plugins. Before the forwarder stops, each VM whose saved listen is `127.0.0.1` and that has a checked address still present on this host is changed to listen on its **first** checked address directly (libvirt VNC has one listen address, so further checked addresses are no longer reachable). VMs with no usable checked address stay on `127.0.0.1` (reachable from the VM Manager web console only). Nothing is set back to every interface. Running VMs pick up the change at their next start.

The cron entry is removed. `/boot/config/plugins/VncBind` (settings, binds, alert state) stays for a reinstall.

## What this does not do

It does not add a longer VNC password, a tunnel, or a claim that the console is private. It does not remove the graphics device.
