# Changelog

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
