---
title: "horizOn February 2025 Update: Unreal SDK, Crash Reports, MCP Server & More"
description: "horizOn ships major updates: Unreal Engine SDK, crash reporting, AI-powered MCP server, mobile-friendly dashboard, global load balancing, and a free self-hosted game backend."
slug: february-2025-massive-update
date: 2025-02-22
keywords:
  - game backend
  - game BaaS
  - game backend as a service
  - unreal engine backend
  - crash reporting games
  - self-hosted game backend
  - indie game backend
  - MCP server
---

# horizOn Just Shipped Its Biggest Update Yet — Here's Everything New

[horizOn](https://horizon.pm) is a game backend built for indie developers — leaderboards, cloud saves, auth, remote config, and more, without the complexity of rolling your own infrastructure. We've been shipping non-stop over the past few weeks, and there's a lot to catch up on. Here's the full rundown.

## Unreal Engine SDK — Full Engine Support

horizOn now officially supports **Unreal Engine** alongside Unity and Godot. That means no matter which engine you use, you get the same straightforward API to plug into leaderboards, cloud saves, authentication, and every other horizOn feature.

The Unreal SDK follows the same patterns as our other SDKs — drop it in, configure your API key, and you're connected. We designed it to feel native to Unreal developers, not like a wrapper bolted on top. If you've ever tried wiring up a game backend in Unreal from scratch, you know how much boilerplate that involves. This skips all of it.

With Unity, Godot, and Unreal covered, horizOn now works with the three engines that power the vast majority of indie games. One backend, every engine.

## Crash Reporting — Know When Things Break

Your players won't always tell you when something crashes. Now they don't have to.

horizOn's new **crash reporting** feature catches crashes automatically, groups them by type, and surfaces them in your dashboard. You see exactly what went wrong, how often it happens, and which errors are trending — all without adding a separate crash reporting tool to your stack.

For indie devs running lean, this matters. You don't need Sentry, Crashlytics, or any third-party integration. Crash data lives right next to your leaderboards and player data, in one place. Ship your game, monitor what breaks, fix it fast.

## MCP Server — AI Meets Your Game Backend

This one's a bit different. We built an **MCP server** for horizOn — that's the [Model Context Protocol](https://modelcontextprotocol.io), the open standard that lets AI assistants interact with external tools and services.

What does that mean in practice? If you're using Claude, Cursor, or any MCP-compatible AI tool, you can connect it directly to your horizOn backend. Query your leaderboard, check player data, manage remote configs, submit test scores — all through natural language in your AI assistant.

It's a new way to interact with your game backend. Instead of switching between your editor, dashboard, and terminal, your AI assistant handles it inline. We're betting that more developers will work this way, and we want horizOn to be ready for it.

## Dashboard Redesign — Cleaner, Faster, Mobile-Ready

The horizOn dashboard got a serious visual overhaul. Cleaner layouts, better information hierarchy, and — importantly — **proper mobile support**.

Whether you're checking your game's stats on your laptop or glancing at player metrics on your phone during a commute, the dashboard now works well on every screen size. We reworked navigation, card layouts, and data tables to be responsive without sacrificing readability.

It's not just cosmetic. We also improved loading performance and streamlined common workflows. The tools you use most are now fewer clicks away.

## Global Load Balancer — One Domain, Worldwide

Previously, horizOn used regional subdomains to route traffic. That meant you had to pick a region and configure your SDK accordingly. Not anymore.

We've rolled out a **global load balancer** that sits in front of all horizOn infrastructure. One domain, one endpoint, worldwide. Your requests get routed to the nearest available server automatically. No regional configuration, no extra setup.

For you as a developer, this simplifies integration. One API URL, done. For your players, it means lower latency regardless of where they are. It's the kind of infrastructure change that's invisible when it works — and that's the point.

## Simple Server — Self-Host Your Game Backend for Free

Not every project needs a managed service. Maybe you're prototyping, maybe you want full control, or maybe you just don't want to depend on someone else's servers.

**horizOn Simple Server** is our free, open-source, self-hosted edition. It's a single PHP application with zero external dependencies — no Docker, no Composer, no Java. Drop it on any PHP hosting and you have a working game backend in minutes.

It's fully API-compatible with the managed horizOn BaaS, so your SDKs work with both. Start with Simple Server for free, switch to the managed service when you need more features or scale. No lock-in, no migration headaches.

Simple Server includes auth, leaderboards, cloud saves, remote config, news, gift codes, feedback, logging, and crash reporting. That's a complete game backend — for free, on your own hardware.

## What's Next

That's six major updates in one push, and we're not slowing down. horizOn is built for indie developers who want a powerful game backend without the overhead of enterprise solutions or the lock-in of big-name platforms.

**[Try horizOn for free at horizon.pm](https://horizon.pm)** — or grab the [Simple Server on GitHub](https://github.com/ProjectMakersDE/horizOn-simpleServer) and self-host it today.
