# Security Policy

## Reporting a Vulnerability

Lokato is a small open-source project maintained by students. We take security
reports seriously — please **do not file public GitHub issues** for security
problems.

Instead, email the maintainers privately at:

> **lokato.security@gmail.com** *(TODO: replace with the address the team
> actually monitors — a shared Gmail or one maintainer's address)*

Please include:

- A description of the issue and the affected component
  (backend / frontend / MQTT / firmware)
- Steps to reproduce, if possible
- Whether the issue is already public or known elsewhere

We aim to acknowledge reports within **7 days** and to ship a fix (or a
mitigation plan) within **30 days** for confirmed issues. As a non-commercial
project we cannot promise faster, but we will keep you in the loop.

## Scope

In scope:

- `lokato-platform` — Laravel backend, Vue frontend, Docker dev stack,
  `start-prod-raspi.sh` Pi-deployment script
- `lokato-main` — ESP32 RFID firmware, Mosquitto config, setup scripts

Out of scope:

- Third-party dependencies (Laravel, Vue, Mosquitto, MariaDB, …) — please
  report to those projects directly
- Attacks that require physical access to the Raspberry Pi or scanner
  hardware (the system is designed for a trusted LAN)

## Threat Model (short version)

Lokato runs **LAN-only**, behind a single nginx on a Raspberry Pi. The system
is **not designed for public internet exposure**. If you deploy it on a public
IP, you do so at your own risk and outside the project's intended threat model.

Authenticated areas (admin endpoints) use Laravel Sanctum bearer tokens. MQTT
broker accepts anonymous connections **because the LAN is the trust boundary**.
If you change that assumption, harden Mosquitto first.
