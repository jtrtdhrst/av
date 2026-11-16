import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { validateMcpDeploy } from '../scripts/validate-mcp-deploy.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const mainSha = 'a'.repeat(40);
const deploySha = 'b'.repeat(40);

function githubMock({ compareStatus = 'behind', runs = [] } = {}) {
  const calls = [];
  return {
    calls,
    rest: {
      repos: {
        getBranch: async (args) => {
          calls.push(['getBranch', args]);
          return { data: { commit: { sha: mainSha } } };
        },
        compareCommits: async (args) => {
          calls.push(['compareCommits', args]);
          return { data: { status: compareStatus } };
        },
      },
      actions: {
        listWorkflowRuns: async (args) => {
          calls.push(['listWorkflowRuns', args]);
          return { data: { workflow_runs: runs } };
        },
      },
    },
  };
}

const successfulRun = {
  head_sha: deploySha,
  head_branch: 'main',
  event: 'push',
  status: 'completed',
  conclusion: 'success',
};
const goodGithub = githubMock({ runs: [successfulRun] });
assert.equal(
  await validateMcpDeploy({ github: goodGithub, owner: 'miloszspyrka-code', repo: 'av', requestedRef: deploySha }),
  deploySha
);
assert.deepEqual(goodGithub.calls[0], ['getBranch', { owner: 'miloszspyrka-code', repo: 'av', branch: 'main' }]);
assert.deepEqual(goodGithub.calls[1], [
  'compareCommits',
  { owner: 'miloszspyrka-code', repo: 'av', base: mainSha, head: deploySha },
]);
assert.deepEqual(goodGithub.calls[2], [
  'listWorkflowRuns',
  {
    owner: 'miloszspyrka-code',
    repo: 'av',
    workflow_id: 'ci.yml',
    branch: 'main',
    event: 'push',
    status: 'completed',
    head_sha: deploySha,
    per_page: 100,
  },
]);

const mainGithub = githubMock({ compareStatus: 'identical', runs: [{ ...successfulRun, head_sha: mainSha }] });
assert.equal(
  await validateMcpDeploy({ github: mainGithub, owner: 'miloszspyrka-code', repo: 'av', requestedRef: 'main' }),
  mainSha
);

await assert.rejects(
  validateMcpDeploy({ github: githubMock(), owner: 'owner', repo: 'repo', requestedRef: 'unreviewed/branch' }),
  /main or a full 40-character commit SHA/
);
await assert.rejects(
  validateMcpDeploy({
    github: githubMock({ compareStatus: 'ahead', runs: [successfulRun] }),
    owner: 'owner',
    repo: 'repo',
    requestedRef: deploySha,
  }),
  /not contained in main/
);
await assert.rejects(
  validateMcpDeploy({ github: githubMock(), owner: 'owner', repo: 'repo', requestedRef: deploySha }),
  /No successful ci\.yml push run/
);
for (const run of [
  { ...successfulRun, event: 'pull_request' },
  { ...successfulRun, head_branch: 'feature/test' },
  { ...successfulRun, conclusion: 'failure' },
  { ...successfulRun, head_sha: mainSha },
]) {
  await assert.rejects(
    validateMcpDeploy({ github: githubMock({ runs: [run] }), owner: 'owner', repo: 'repo', requestedRef: deploySha }),
    /No successful ci\.yml push run/
  );
}

const workflow = fs.readFileSync(path.join(ROOT, '.github', 'workflows', 'deploy-mcp.yml'), 'utf8');
assert.match(workflow, /if: github\.ref == 'refs\/heads\/main'/);
assert.match(workflow, /needs: validate/);
assert.match(workflow, /validate:[\s\S]*?actions: read/);
assert.match(workflow, /deploy:[\s\S]*?permissions:\s+contents: read/);
assert.match(workflow, /context\.repo\.owner !== 'miloszspyrka-code' \|\| context\.repo\.repo !== 'av'/);
assert.match(workflow, /ref: \$\{\{ needs\.validate\.outputs\.deploy_sha \}\}/);
assert.match(workflow, /MCP_BUILD_SHA: \$\{\{ needs\.validate\.outputs\.deploy_sha \}\}/);

const deployScript = fs.readFileSync(path.join(ROOT, 'scripts', 'deploy-mcp.sh'), 'utf8');
assert.match(deployScript, /health\.revision !== process\.env\.MCP_BUILD_SHA/);
assert.match(deployScript, /health\.ok !== true/);
assert.match(deployScript, /health\.mcp\?\.writesEnabled !== expectedWrites/);
assert.match(deployScript, /health\.mcp\?\.tools !== expectedTools/);
assert.match(deployScript, /MCP_BUILD_SHA="\$MCP_BUILD_SHA"/);
assert.match(deployScript, /DRY_RUN="\$\{DRY_RUN:-true\}"/);
assert.match(deployScript, /case "\$DRY_RUN" in\s+true\|false\)/);

console.log('MCP deploy guard PASS');
