# KioTrad delivery project

Issues hold the task and acceptance criteria. Pull requests hold implementation.
The Project groups those same issues by progress and priority. Merging code means
Ready to test, not automatically Done. Confirm acceptance before closing an issue.

Workflow: **Backlog → Ready → In progress → Review → Ready to test → Done**.
Fields: Status, Priority (High/Medium/Low), Area (UI/Sync/Data).

## One-time GitHub CLI setup on the Mac

```bash
brew install gh
gh auth login --hostname github.com
gh auth refresh --hostname github.com --scopes project
```

Use the existing Kiyoonewton account with repository access. The `project` scope
permits managing user Projects. Do not paste tokens into commands, source files
or chat; the script uses GitHub CLI's existing authentication. Fine-grained tokens
also need the relevant project/repository permissions. It does not read a database
password or contact production.

## Create or resume the project

```bash
cd "$HOME/Developer/kiotrad"
git pull --ff-only --no-rebase origin main &&
python3 -u engine/github/setup_project.py
```

This command performs GitHub writes: it creates a private **KioTrad** project under
Kiyoonewton if none exists, links the repository, configures workflow fields, adds
a **Delivery board** view, and adds existing issues #92/#93/#94. It does not create
new issues, edit task descriptions, close issues, merge PRs or touch application
data. Initial placements are UI verification and sync measurements in Ready to test,
with measured optimization in Backlog. Already-closed tasks start Done.

Rerunning discovers the project and existing items before adding anything. It
fills missing values without resetting manual status/priority/area choices.
Existing option IDs and custom options are preserved. For a newly created project,
GitHub's initial Todo is renamed Backlog while retaining its ID. Existing project
visibility and custom view layouts are not changed. The setup refuses ambiguous
project names, incompatible field types or more than 100 fields/views rather than
silently replacing custom setup. Project and item lookups paginate.

If multiple open projects have the same name, choose one explicitly:

```bash
python3 -u engine/github/setup_project.py --project-number NUMBER
```

The final URL opens the project; select **Delivery board**. If a custom project
uses another grouping field, select Status as the board grouping in GitHub. This
script does not configure merge/closure automation or continuously synchronize
future issues. Add future issues to this project when creating them; move verified
work to Done and close its issue. Closed/merged work is not retroactively imported
by this initial three-card setup.

GitHub API operations are not one transaction. If access or a request fails, fixes
remain in place; rerun to resume. Existing card values are preserved, including on
closed issues, rather than overwriting someone else's workflow. The command reports
only sanitized errors, not CLI stderr or credentials. A live run with the user's
GitHub CLI authentication is still required to validate the account/API setup.

Official API reference: https://docs.github.com/en/issues/planning-and-tracking-with-projects/automating-your-project/using-the-api-to-manage-projects
