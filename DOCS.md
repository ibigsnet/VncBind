# VNC Bind

A VM template save in Virtual Machines writes the VNC listen address. The form builder uses every interface. On a server with an internet address, that publishes the console. VNC passwords are at most 8 characters, so the listen address is the control this plugin keeps.

## Settings

Settings → User Utilities → VNC Bind. The same Enable or Disable choice is in Settings → VM Manager, advanced view.

- **Interjection** — Enable asks, before the template is saved, which addresses VNC should open. Disable leaves the save alone. Install defaults to Enable.
- **Use Interface Extra** — Yes, the default, follows Settings → Network Settings → [Interface Extra](/Settings/NetworkExtra). That page lists interfaces to include and interfaces to exclude. Included interfaces are added to `br0`, `bond0`, `eth0`, and `wlan0`. Excluded interfaces are dropped. No uses only the checked interfaces on this page.
- **Allowed interfaces** — interfaces that appear in the question. None checked offers the set above. A checked interface is offered even when it is outside that set. An Interface Extra exclude is still dropped. The question can open more than one address, including an IPv4 address and an IPv6 address on the same interface. `0.0.0.0` is not written. An internet address stays closed unless it is checked. The console on this server uses the local address, and each checked address is opened for VNC clients.
- **Alerting** — Enable, the default, sends an Unraid notification when an internet address is listed and a saved or running VNC listen address is every interface (`0.0.0.0` or `::`). No password is an alert. A password set is a warning. Disable sends nothing. The same VM is not notified again until that condition changes. Apply, Check now, and a VM save run the check, and Enable also checks every five minutes.
- **Internet address** — addresses on this host that alerting treats as reachable from the internet. Tailscale and private LAN addresses are left out: `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10`, IPv6 unique-local (`fc00::/7`), and IPv6 link-local. A bridge shim that repeats the same address is not listed again. Alerts run only when an address is listed.
- **Template check** — Off, Daily, or Weekly. The check lists VMs whose saved or running VNC listen address is every interface. It does not edit them. Check now runs that list once.

XML view is rewritten before the save is sent. Form view is redefined with the local console address as soon as VM Manager finishes creating the VM. Saving a reusable template from form view still uses the address VM Manager writes.

A running VM keeps its current VNC socket until that VM is restarted.

## What this does not do

It does not add a longer VNC password, a tunnel, or a claim that the console is private. It does not remove the graphics device.
