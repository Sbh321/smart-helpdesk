#!/usr/bin/env sh
# commit-msg hook (lefthook.yml): the subject must follow Conventional Commits.
# A script rather than an inline `run:` because lefthook on Windows wraps inline commands in
# `sh -c "…"`, which breaks on the double quotes of the message below.
subject="$(head -n 1 "$1" | tr -d '\r')"
# Git's own merge subjects ("Merge branch 'feat/x' into dev", "Merge remote-tracking branch …",
# "Merge pull request #12 from …", "Merge tag …", "Merge commit …") pass as they are: they record a
# merge, not a change, and rewriting them by hand loses the branch names.
if printf '%s\n' "$subject" | grep -Eq "^Merge (branch|branches|remote-tracking branch|pull request|tag|commit) "; then
  exit 0
fi
pattern='^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([a-z0-9-]+\))?!?: .{1,100}$'
if ! printf '%s\n' "$subject" | grep -Eq "$pattern"; then
  echo "Commit subject must follow Conventional Commits: type(scope): summary"
  exit 1
fi
