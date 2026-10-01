# VNC Bind

A VM template save in Virtual Machines writes the VNC listen address. The form builder uses every interface. On a server with a public address, that publishes the console. VNC passwords are at most 8 characters, so the listen address is the control this plugin keeps.

## Settings

Settings → User Utilities → VNC Bind. The same Enable or Disable choice is in Settings → VM Manager, advanced view.

- **Interjection** — Enable asks, before the template is saved, which allowed interface the VNC listen address should use. Disable leaves the save alone. Install defaults to Enable.
- **Ignore Interface Extra** — No, the default, follows Settings → Network Settings → Interface Extra. Excluded interfaces are not offered. Included interfaces are added to the management interfaces (`br0`, `bond0`, `eth0`, `wlan0`). Yes uses only the checkboxes on this page.
- **Listen interfaces** — interfaces VNC may bind. One listen address is written. VNC has one listen address per graphics element.
- **Alerting** — Enable, the default, sends an Unraid notification when this host has a public address and a saved or running VNC listen address is every interface (`0.0.0.0` or `::`). No password is an alert. A password set is a warning. Disable sends nothing. The same VM is not notified again until that condition changes. Apply, Check now, and a VM save run the check, and Enable also checks every five minutes.
- **Public address** — Yes when an address on this host is outside Tailscale, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10`, IPv6 unique-local (`fc00::/7`), and IPv6 link-local. Alerts run only when this is Yes.
- **Template check** — Off, Daily, or Weekly. The check lists VMs whose saved or running VNC listen address is every interface. It does not edit them. Check now runs that list once.

XML view is rewritten before the save is sent. Form view is redefined with the chosen address as soon as VM Manager finishes creating the VM. Saving a reusable template from form view still uses the address VM Manager writes.

A running VM keeps its current VNC socket until that VM is restarted.

## What this does not do

It does not add a longer VNC password, a tunnel, or a claim that the console is private. It does not remove the graphics device.
