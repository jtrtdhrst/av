const SHA_PATTERN = /^[a-f0-9]{40}$/i;

export async function validateMcpDeploy({ github, owner, repo, requestedRef }) {
  const ref = String(requestedRef ?? '').trim();
  if (ref !== 'main' && !SHA_PATTERN.test(ref)) {
    throw new Error('ref must be main or a full 40-character commit SHA.');
  }

  const main = await github.rest.repos.getBranch({ owner, repo, branch: 'main' });
  const sha = ref === 'main'
    ? main.data.commit.sha.toLowerCase()
    : ref.toLowerCase();
  const comparison = await github.rest.repos.compareCommits({
    owner,
    repo,
    base: main.data.commit.sha,
    head: sha,
  });
  if (!['identical', 'behind'].includes(comparison.data.status)) {
    throw new Error('The requested revision is not contained in main.');
  }

  const runs = await github.rest.actions.listWorkflowRuns({
    owner,
    repo,
    workflow_id: 'ci.yml',
    branch: 'main',
    event: 'push',
    status: 'completed',
    head_sha: sha,
    per_page: 100,
  });
  const verified = (runs.data.workflow_runs ?? []).some((run) =>
    run.head_sha?.toLowerCase() === sha
    && run.event === 'push'
    && run.head_branch === 'main'
    && run.status === 'completed'
    && run.conclusion === 'success'
  );
  if (!verified) {
    throw new Error(`No successful ci.yml push run on main was found for ${sha}.`);
  }

  return sha;
}
