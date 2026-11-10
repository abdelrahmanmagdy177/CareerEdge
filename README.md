# CareerEdge

Interview simulation and CV review marketplace. Candidates book timed chat or voice sessions with
freelance HR or technical recruiters, pay up front (the money is held until the session is done),
and get structured feedback.

**Stack:** .NET 9 (ASP.NET Core) · PostgreSQL 16 · SignalR · LiveKit (voice) · Hangfire · QuestPDF

## Docs

| File | What it is |
|---|---|
| [`docs/PLAN.md`](docs/PLAN.md) | Scope, architecture, domain model, session lifecycle, business rules, milestones |
| [`docs/openapi.yaml`](docs/openapi.yaml) | **API contract.** Mobile and web clients are built from it, and it is the source of truth |

### For app developers

- **Start here:** [`docs/MOCK-API.md`](docs/MOCK-API.md). Run `docker compose up mock` to get a
  working API at `http://localhost:4010` with realistic data, before the backend exists.
- View the contract by pasting `docs/openapi.yaml` into <https://editor.swagger.io>, or by
  running `npx @redocly/cli preview-docs docs/openapi.yaml`.
- Generate a client with `openapi-generator-cli generate -i docs/openapi.yaml -g dart-dio`.
  Other generators: `kotlin`, `swift5`, `typescript-axios`.
- Operations marked `x-phase: 2..5` are planned features. Their shape is fixed, but they return
  404 until the phase ships.
- The realtime chat events (SignalR hub `/hubs/sessions`) are in `docs/PLAN.md` §5.

## Status

Phase 1 (Core MVP) is being planned. The backend code is not written yet.
