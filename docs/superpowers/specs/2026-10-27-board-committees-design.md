# Board: committees organigram, meeting delete, no quorum — design

Owner decisions, 2026-10-27.

## 1. Meetings

- **Delete** joins add / edit / cancel. Any status (scheduled, held, cancelled), behind a
  confirm dialog. Needs Board → delete (`board.meetings.destroy`).
  - Attendance rows go (cascade); minutes file erased from disk; linked tasks are kept
    but lose their meeting link (`board_meeting_id` → null).
  - Buttons: meeting page header (next to Cancel) and each row of the meetings list.
  - Activity: `meeting_deleted`.
- **Quorum** removed from the UI everywhere: create forms (Meetings, Calendar), edit
  form, attendance counter shows present count only. Column `quorum_required` stays
  (no data lost); it is no longer validated or written.

## 2. Committees

- Board **role** stays (President, Secretary-General, Treasurer…). Committees are added
  on top: a member can sit on **several** committees.
- Position in a committee: **Head** (max 1), **Deputy** (max 2), **Member** (any).
  Limits count members of the same term.
- Committee list (Finance, Sports, Media…) = new App Configuration list
  **Settings → Committees** (`board-committees`, module `board_committees`, own right;
  starts empty except system roles, like the 2026-10-07 split).
- Global list; membership follows the board member, so it is per term.

### Tables

- `board_roles.level`: `top` | `bureau` | `member` (default `member`). Seeded:
  president → top; vice_president, secretary, treasurer → bureau.
- `board_committees`: name, sort_order.
- `board_committee_member`: board_committee_id, board_member_id, position
  (`head`|`deputy`|`member`), unique pair.

## 3. Members page = graphical organigram (editor)

Selected term (existing term bar). Rows:

1. **Top** — one box per active member whose role level is `top`; an empty dashed box
   per top role with nobody in it.
2. **Bureau** — same for `bureau` roles.
3. **Committees** — one box per committee: Head slot, 2 Deputy slots, Members list.
4. **Other members** — active members with a `member`-level role and no committee.
5. **Former members** — collapsed, greyed.

Click an **empty slot** → player picker → saved in that position:
- role slot: creates the board member with that role (if the player is already a
  member of this term, their role changes instead — no duplicate per player+term);
- committee slot: finds or creates the player's board member for the term (default
  role = first `member`-level role) and seats them with that position.

Click a **filled slot** → small menu: change committee position / edit member (role,
status) / remove from committee / remove from board.

Export gains a Committees column ("Finance (Head), Media (Member)").
