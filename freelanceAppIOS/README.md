# freelanceAppIOS — دُرر للمحترفين (iOS)

The freelancer app for iPhone: the SwiftUI twin of [`freelanceApp/`](../freelanceApp) (Android is the reference), on the
same API (`/api/freelance/v1/*`, guard `freelance_api`). Bundle id `com.dorr.freelance`, iOS 16+, XcodeGen target `DorrPro`.

## Run

```bash
cd freelanceAppIOS
cp Config/Local.xcconfig.example Config/Local.xcconfig   # your backend: DORR_API_HOST / DORR_API_SCHEME
xcodegen
xcodebuild -project DorrPro.xcodeproj -scheme DorrPro -destination 'generic/platform=iOS Simulator' \
  -derivedDataPath build CODE_SIGNING_ALLOWED=NO build
```

`scripts/typecheck.sh <paths>` compiles the shared core plus the given files against the simulator SDK without an Xcode
build (fast, no DerivedData).

## Layout (Android twin in brackets)

| Swift | Kotlin |
|---|---|
| `DorrPro/App/` — entry, `RootView` (routes, shell, gate, push taps), `Routes.swift` (`Route`, `Nav`, `ShellState`) | `MainActivity`, `FreelanceApp`, `navigation/FreelanceNavGraph.kt` |
| `DorrPro/Net/` — `Api.swift` (`ApiResult`, envelope, errors), `Session.swift`, `Realtime.swift`, one `*Api.swift` per Kotlin API | `net/*` + `driverApp/core` |
| `DorrPro/UI/Kit`, `UI/Components`, `UI/Theme`, `UI/Wallet` | `ui/kit`, `ui/components`, `ui/theme`, `ui/wallet` |
| `DorrPro/UI/Screens/{Auth,Work,Profile,Agreement,Services,Growth,Wallet,Account,Support}` | `ui/screens/*.kt` (same file names) |
| `DorrPro/UI/Screens/Workroom/` (FS-150e: `WorkroomScreen`, `Tasks`, `Files`, `FileScreen`, `Decisions`, `Timeline`, `Kit`, `Parts`) | `ui/screens/workroom/*.kt` (same file names) |

## Rules

- **Android → iOS sync:** a change in `freelanceApp/` is made here in the same task, in the twin file, keeping names
  (screens, API functions, DTO fields).
- **Strings:** edit only `freelanceApp/app/src/main/res/values*/strings.xml`; `scripts/sync_strings.py` regenerates
  `Localizable.strings` on every build. Never edit the generated files.
- **No polling:** live events (Pusher, the freelancer's private channel) and push (OneSignal) — settings from
  `GET freelance/v1/realtime-config`; one reload on reconnect. Countdowns run on the phone's clock.

## Not identical to Android (platform)

- No system back key: each page's own back button does what Android's `BackHandler` does.
- Face ID is always "strong" (Android's weak face mode doesn't exist on iOS).
- The invite link still points to Google Play until the app has an App Store id.
- Rate app uses `SKStoreReviewController`; files open in Quick Look; pickers are `PhotosPicker` / `.fileImporter`.
- Teams (FS-209, `Net/TeamsApi.swift`, `UI/Screens/Work/Team{Parts,Screens,DetailScreen,MembersSection,InvitesSection}.swift`): the logo is
  picked with `PhotosPicker` (Android's `GetContent`) and sent by `logoPart` (field `logo`); an invitation code is copied with
  `UIPasteboard` and shared with the system share sheet (`teamCopyText` / `teamShareText` — Android's `copyText` / `shareText`, renamed because
  `AgreementRecords.swift` already owns a private `shareText`). The edit-team and manage-person bottom sheets are `.cmSheet` sheets that close
  themselves (no `onDismiss` parameter), and the two questions the manage sheet can ask (make Owner, take out) are presented from the sheet
  itself, because a cover can't open over the page while a sheet is up. `abilities` is looked up in both spellings (`view_price` / `viewPrice`)
  because the snake_case decoder also converts the keys of a dictionary (`teamAbility`).
- Working as a team (FS-209b, `Net/TeamsApi.swift` projects / workload / assignees, `UI/Screens/Work/TeamWork.swift`, `OfferTeamParts.swift`):
  ported from `TeamWork.kt` / `OfferTeamParts.kt` and the FS-209b edits of `MyWorkScreen`, `TeamDetailScreen`, `AgreementScreen` and
  `OfferScreens` (the «Me | My team» switch, the team's Projects and Workload parts, the access banner, the people on a part and the sheet that
  changes them, an offer in a team's name — picker, signer, staff — and the team badge). Money the server hides arrives as `null`, so the amounts
  are optional (`AgreementDto.showMoney` / `showNet`). The assignees sheet is a `.cmSheet` that closes itself (Android passes `onDismiss`).
  Android's `MyOfferCard` has no twin here (only `OfferListCard` and the offer page), so the team badge is on those two. **Not compiled**:
  there was no Swift toolchain when this was written — run `typecheck.sh` / the build check before shipping.
- The team's space (FS-209c — `Net/TeamsApi.swift` chat / verification, `Net/PortfolioApi.swift` `TeamPortfolioApi`, `Net/Realtime.swift`
  `listenTeam`, `UI/Screens/Work/TeamChat.swift` and `TeamVerificationScreen.swift`, plus the FS-209c edits of `TeamDetailScreen`,
  `PortfolioScreens`, `Routes`, `RootView` and `NotificationsApi`): the team's inside chat (a «Chat» part with the threads, a project picker, the
  thread page), the team's portfolio (`PortfolioTab` / `PortfolioFormScreen` take an optional `team`; a `PortfolioSource` picks the endpoint) and the
  team's verification (an Owner sends the papers). The thread page listens to `private-freelance-team.{uuid}` / `freelance.internal` only while
  it is open (`Realtime.listenTeam` / `stopListeningTeam`, refcounted) and reads again on open, on a notice and on reconnect — no timer.
  Taking a message back is a `.contextMenu` (Android: a long-press `DropdownMenu`). The «open a project chat» and «add a paper» sheets are
  `.cmSheet` sheets that close themselves (Android passes `onDismiss`; their busy flag is hoisted to the page for `locked:`). A paper is
  picked with `.fileImporter` (JPEG / PNG / PDF; Android's `OpenDocument`) and opened in Quick Look. The notice router sends
  `freelance.internal_message` to the thread and the three `freelance.team_verif*` events to the verification page. **Not compiled**: there was no
  Swift toolchain when this was written — run `typecheck.sh` / the build check before shipping.
- The Workroom (FS-150e — `Net/WorkroomApi.swift`, `Net/Realtime.swift` `listenWorkroom`, `UI/Screens/Workroom/*.swift`, plus the FS-150e edits of
  `AgreementScreen` (the `WorkroomEntryCard`), `Routes` (`.workroom(project:tab:)`, `.workroomFile(project:file:)`), `RootView` and
  `NoticeRoute`): the tasks, the files (versions and notes), the decisions both sides agree and the timeline of a project, from the agreement
  page. The page listens to `private-freelance-workroom.{uuid}` / `freelance.workroom` while it is open (`listenWorkroom` /
  `stopListeningWorkroom`, refcounted like the team's) and a burst of events, notices and reconnects is **one** read after 350 ms of the lists it
  touches (`WorkroomModel.touch` / `FileModel.changed`) — no timer. The helper views that would collide are renamed: Android `WorkCard` →
  `WorkroomCard` (the community already owns `WorkCard`). The lists are `PagedRows` (30 a page, the newest read wins, so a filter changed during
  a read is not lost) inside `FeedList`; pulling a list down reads it again (`.refreshable`, Android has no pull). A task's status is a `Menu`
  + `Picker` on its round icon (Android: a `DropdownMenu`); the card is a tap gesture, not a `Button`, because it holds buttons of its own.
  A version opens by reading it with the sign-in (`WorkroomApi.download` → `caches/workroom`) and Quick Look (`ProfileFileViewer`); the file to
  upload is picked with `.fileImporter` (any type, then checked against the server's `upload` rules — size and extension — before it is sent).
  The bottom sheets are `.cmSheet` sheets that close themselves (Android passes `onDismiss`; their busy flag is hoisted to the tab for
  `locked:`), the questions (delete a task / file / note, withdraw a decision) are `.dorrDialog`, and errors of a sheet stay in the sheet.
  Android's per-tab `toast` callbacks are `AppToast.show`. The file page takes the whole screen (`ShellState.immersive`) for its composer.
  **Not compiled**: there was no Swift toolchain when this was written — run `typecheck.sh` / the build check before shipping.
- A team's plan (FS-211 — `Net/TeamPlanApi.swift`, `UI/Screens/Work/TeamPlanScreen.swift`, plus the FS-211 edits of `TeamDetailScreen`, `Routes`
  and `RootView`): ported from `TeamPlanApi.kt` / `TeamPlanScreen.kt` — the Owner's usage meters, the plan and who pays, the plans with a monthly /
  yearly price and the invoices. `planMoney`, `PlanStatusPill`, `Benefit` and `periodName` are no longer `private` in `PlansScreen.swift` (the two
  screens share them, like `StatusPill` / `Benefit` on Android). The meter bar is a `GeometryReader` capsule. **Not compiled**: there was no Swift
  toolchain when this was written — run `typecheck.sh` / the build check before shipping.
- A signing Owner (FS-212 / D12 — `Net/TeamsApi.swift` `signedAgreements` on `TeamMembersMeDto` / `TeamMemberDto`, and `TeamDetailScreen.swift` / `TeamMembersSection.swift`): ported from `TeamsApi.kt` / `TeamDetailScreen.kt` / `TeamMembersSection.kt` — «Leave the team», the role chips and «Remove from the team» are locked, with the reason under them, while the Owner signed agreements of the team that have not ended or has offers in its name that are still open (`openOffers` next to `signedAgreements`). **Not compiled**: there was no Swift toolchain when this was written — run `typecheck.sh` / the build check before shipping.
