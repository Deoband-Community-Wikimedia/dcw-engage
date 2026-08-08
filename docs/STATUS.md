# DCW Engage — Status & Working Plan

Living document. Tracks what is built, what is left, and the decisions we
still owe each other. Maps against the scope in #1. Update it as things land
rather than letting it drift.

Last updated: 2026-07-24

---

## Where we actually are

The foundation and the security plumbing are in good shape. What is **not**
built yet is most of the form-type-specific behaviour that #1 asked for — the
multi-step flows, the conditional and nested dropdowns, the role-specific
questions. The visual builder today produces solid generic forms (short
answer, paragraph, email, flat dropdown, file upload), and everything around
those forms — auth, magic links, dedup, retention, notes, alert plumbing — is
wired. The specialised per-form UX is the next big chunk of work.

Put simply: the engine runs, the rails are laid, the seven specific form
experiences are still ahead.

---

## Requirement status (against #1)

Legend: ✅ done · 🟡 partial · ⬜ not started

### Platform & infrastructure
| Item | State | Notes |
|---|---|---|
| Organizers create form endpoints mapped to DB | ✅ | Visual builder + auto slug |
| Save draft + time-sensitive resume link | ✅ | Magic links, email-bound token |
| Lock to one email / prevent duplicate spam | ✅ | App-layer check + `UNIQUE(form_id, email)` (PR for #5) |
| File auto-rename to standard format | ✅ | `FileUploader` |
| Data retention scrub (6–12 mo) | ✅ | Cron; hardened in PR for #5 |
| Admin authentication | ✅ | Session login, guards all `/admin/*` (#7) |
| Routing / clean URLs | ✅ | Front-controller rewrite (#8) |
| Internal organizer notes | ✅ | Append-only thread (#6) |
| Route alert to specific organizer | 🟡 | Delivery built (#6); **no builder UI to set recipients**, and SMTP partially configured |
| Confirmation email on submit | 🟡 | Magic-link email sends on submit; no distinct confirmation template; **SMTP not live** |
| Malware scan on upload | ⬜ | Only MIME + extension + double-extension checks today |

### The seven form experiences
| Form | Needs | State |
|---|---|---|
| Scholarship | Multi-step progress tracking | ⬜ |
| Heritage Lens Fellowship | Multi-step + mandatory portfolio upload | 🟡 upload works, multi-step ⬜ |
| Internship | CV upload + portfolio (upload or link) | 🟡 upload works, either/or logic ⬜ |
| Volunteering | Role dropdown that reveals role-specific questions | ⬜ conditional logic |
| Course Registration | Structured fields + batch select + T&C | 🟡 basic fields yes, T&C gate ⬜ |
| Club Initiatives | Nested Club → Active Call dropdowns | ⬜ nested logic |
| Community Feedback | Rating scales + open text | ⬜ no rating field type |

### UX requirements
| Item | State | Notes |
|---|---|---|
| Inline validation, no page reload | ⬜ | Validation is server-side today (full reload) |
| Conditional fields with smooth transitions | ⬜ | No client-side conditional engine yet |
| Responsive layout | 🟡 | Renders on mobile; not tuned |
| Drag & drop upload (desktop) | ⬜ | Standard file input only |

### Admin workspace
| Item | State | Notes |
|---|---|---|
| Leave internal notes | ✅ | #6 |
| Per-application status change | ✅ | Single-row dropdown |
| Bulk status updates | ⬜ | |
| Filter applications by club / role | ⬜ | |

---

## Remaining work, grouped

**Phase A — make what exists actually usable in production**
- Build the `notify_emails` field in the builder so alerts have recipients
- A distinct confirmation email separate from the magic-link email
- SMTP config is **deliberately deferred to final-launch prep**. Until it is
  set, all email is a silent no-op — so magic-link resume, the confirmation,
  and organizer alerts cannot be tested end to end yet. Don't file those as
  bugs before launch.

**Phase B — the form engine's missing capabilities** (the big one)
- New field types: rating scale, checkbox/consent (T&C), radio
- Conditional visibility (show field X when answer Y is selected)
- Nested dropdowns (Club → Active Call), driven by builder-defined data
- Multi-step forms with progress tracking
- Client-side inline validation so conditional fields work without reloads

**Phase C — upload & admin polish**
- Drag & drop upload, mobile-tuned file fields
- Malware scan step in the upload pipeline
- Admin: filter by club/role, bulk status updates

**Phase D — hardening & compliance sign-off**
- Rate limiting / abuse protection on public submit
- Retention policy confirmed with the team (see open decisions)
- Accessibility pass

---

## Open decisions we still owe each other

These were raised and never closed. They block design, not just code.

1. **Edit vs lock.** The "one email = one application" rule shouldn't block a
   legitimate correction. Current behaviour: locked once an organizer moves it
   to Under Review. Is that the intended line? (Raised by Gauri, 4d ago.)
2. **Magic-link token expiry.** Draft vs edit windows — current config is
   `+7 days` draft, `+2 hours` edit. Confirm these are right.
3. **Club / Call data model.** Is a "form" one row per club/call, or one form
   with nested logic in `schema_json`? This decides whether a single
   `notify_emails` per form is enough or whether routing keys off the answer.
   (Raised by Gauri, blocks the nested-dropdown work.)
4. **Scrubber redaction policy.** The retention scrub currently redacts *all*
   answer fields. #1 mentions keeping "just the name of the winner" for
   archive. Do we preserve a minimal record (name, which program) or fully
   anonymize? (Open in PR for #5.)

---

## Suggested next step

Settle the four decisions above first — they're cheap to answer and block
design. In parallel, the `notify_emails` builder field is a quick win. Then
scope Phase B properly, since it's the largest chunk and decision #3 (the
Club→Call data model) shapes it. SMTP stays off until final-launch prep by
choice, so email-dependent flows are validated then, not now. Timeline to be
set once we agree who picks up what.
