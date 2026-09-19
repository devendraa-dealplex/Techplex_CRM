Deployment overlay - AI Agents & Knowledge Base fixes
======================================================

Drop this folder's contents into the application root (paths below are
already relative to the project root, e.g.
modules/payplex_ai_agents/controllers/Agents.php) to overwrite the fixed
files in place.

Corresponds to git commit 5526bba on branch Devendra.

Files included (10):

modules/payplex_ai_agents/controllers/Agents.php
  - Server-side validation on create/edit (required name, confidence
    0-1, non-negative limits/budgets, duplicate-name check)
  - Real staff-existence validation for Owner/Reviewer/Approver
  - Added destroy() action (agent delete)
  - Enforces configured Approver on the approve action

modules/payplex_ai_agents/controllers/Knowledge.php
  - Added destroy() action (KB entry delete)
  - Fixed JS-breaking quote bug in the "Ask" test success message
  - ask() now returns JSON for the AJAX result box

modules/payplex_ai_agents/libraries/Payplex_agent_lifecycle.php
  - guardApproval() now enforces the agent's configured approver_id
    (maker-checker upgrade)

modules/payplex_ai_agents/libraries/Payplex_agent_safety.php
  - checkBudget()/budgetStatus(): a budget of $0 now correctly means
    "no spend allowed" instead of "unlimited"
  - isApprovalRequired()/isNeverAutonomous() now also check the
    agent's own Prohibited/Approval-required action lists, not just
    the fixed global list

modules/payplex_ai_agents/libraries/Payplex_agent_sandbox.php
  - Passes the agent's own prohibited/approval-required lists into
    the safety gate and the transcript preview

modules/payplex_ai_agents/models/Payplex_ai_agents_model.php
  - Added deleteAgent(), kbDelete(), agentNameTaken(), staffExists()
  - transition() passes approver_id through to the lifecycle guard
  - sandboxTest() now feeds real month-to-date spend into the budget
    check

modules/payplex_ai_agents/views/agent_form.php
  - Real staff-select dropdowns for Owner/Reviewer/Approver
  - AI provider/model autocomplete (datalist)
  - Re-renders with entered values preserved on a validation error

modules/payplex_ai_agents/views/agent_view.php
  - Added Delete button
  - Added Schedule row (working days/hours/timezone)
  - Owner/Reviewer/Approver and Created/Submitted/Approved-by now
    resolve to real staff names instead of bare numeric ids

modules/payplex_ai_agents/views/agents_list.php
  - Added Delete button per row

modules/payplex_ai_agents/views/kb_list.php
  - Added Delete button per row
  - Added the persistent AJAX result box for the "Ask" test tool

NOT included: no files under modules/payplex_ai_agents/controllers/Templates.php
or views/templates*.php were changed - no bugs were found or fixed in the
Templates/Agent-Templates area this session.

Full details and verification notes for each fix are in bugs.csv at the
project root (BUG-001 through BUG-006 and BUG-009 through BUG-018).
