import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { assertMcpDeployContext, validateMcpDeploy } from '../scripts/validate-mcp-deploy.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const mainSha = 'a'.repeat(40);
const deploySha = 'b'.repeat(40);

function githubMock({ compareStatus = 'behind', runs = [], runPages = null, totalCount = runs.length } = {}) {
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
          return {
            data: {
              workflow_runs: runPages?.[args.page] ?? runs,
              total_count: totalCount,
            },
          };
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
assert.doesNotThrow(() => assertMcpDeployContext({
  ref: 'refs/heads/main', owner: 'miloszspyrka-code', repo: 'av',
}));
for (const context of [
  { ref: 'refs/heads/feature/test', owner: 'miloszspyrka-code', repo: 'av' },
  { ref: 'refs/pull/1/merge', owner: 'miloszspyrka-code', repo: 'av' },
  { ref: 'refs/heads/main', owner: 'attacker', repo: 'av' },
  { ref: 'refs/heads/main', owner: 'miloszspyrka-code', repo: 'other' },
]) {
  assert.throws(() => assertMcpDeployContext(context), /Deploy AlphaVault MCP/);
}
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
    page: 1,
  },
]);

const failedPage = Array.from({ length: 100 }, () => ({ ...successfulRun, conclusion: 'failure' }));
const paginatedGithub = githubMock({
  runPages: { 1: failedPage, 2: [successfulRun] },
  totalCount: 101,
});
assert.equal(
  await validateMcpDeploy({ github: paginatedGithub, owner: 'miloszspyrka-code', repo: 'av', requestedRef: deploySha }),
  deploySha
);
assert.deepEqual(paginatedGithub.calls[3], [
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
    page: 2,
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
const permissionsMatch = /^permissions:\r?$/m.exec(workflow);
const triggerMatch = /^on:\r?$/m.exec(workflow);
assert.ok(triggerMatch && permissionsMatch && permissionsMatch.index > triggerMatch.index, 'workflow trigger block must be explicit');
const triggerBlock = workflow.slice(triggerMatch.index, permissionsMatch.index);
assert.deepEqual(
  [...triggerBlock.matchAll(/^  ([\w-]+):\r?$/gm)].map((match) => match[1]),
  ['workflow_dispatch'],
  'MCP production deploy must only support manual dispatch'
);

function jobBlock(name, nextName = null) {
  const header = new RegExp(`^  ${name}:\\r?$`, 'm').exec(workflow);
  assert.ok(header, `missing ${name} job`);
  const start = workflow.indexOf('\n', header.index) + 1;
  const next = nextName === null ? null : new RegExp(`^  ${nextName}:\\r?$`, 'm').exec(workflow);
  const end = nextName === null ? workflow.length : next?.index ?? -1;
  assert.ok(end > start, `missing next job after ${name}`);
  return workflow.slice(start, end);
}

const validateJob = jobBlock('validate', 'deploy');
const deployJob = jobBlock('deploy');
assert.match(validateJob, /^    if: github\.ref == 'refs\/heads\/main'$/m);
assert.match(deployJob, /^    if: github\.ref == 'refs\/heads\/main'$/m);
assert.match(deployJob, /^    needs: validate$/m);
assert.match(validateJob, /^    permissions:\r?\n      contents: read\r?\n      actions: read$/m);
assert.match(deployJob, /^    permissions:\r?\n      contents: read$/m);

const validateScript = validateJob.match(/^          script: \|\r?\n([\s\S]*)$/m)?.[1] ?? '';
assert.doesNotMatch(
  validateScript,
  /assertMcpDeployContext\(context\)/,
  'github context must be mapped to flat fields before validation'
);
assert.match(
  validateScript,
  /assertMcpDeployContext\(\{\s*ref: context\.ref,\s*owner: context\.repo\.owner,\s*repo: context\.repo\.repo,?\s*\}\)/
);
assert.match(validateScript, /validateMcpDeploy\(/);
assert.match(deployJob, /ref: \$\{\{ needs\.validate\.outputs\.deploy_sha \}\}/);
assert.match(deployJob, /MCP_BUILD_SHA: \$\{\{ needs\.validate\.outputs\.deploy_sha \}\}/);

const deployScript = fs.readFileSync(path.join(ROOT, 'scripts', 'deploy-mcp.sh'), 'utf8');
const healthGateStart = deployScript.indexOf('node -e \'', deployScript.indexOf('MCP_HEALTH_BODY="$body"'));
const healthGateEnd = deployScript.indexOf("\n  ' || fail", healthGateStart);
assert.ok(healthGateStart >= 0 && healthGateEnd > healthGateStart, 'health validations must run in the deploy gate');
const healthGate = deployScript.slice(healthGateStart, healthGateEnd);
assert.match(healthGate, /health\.ok !== true/);
assert.match(healthGate, /health\.revision !== process\.env\.MCP_BUILD_SHA/);
assert.match(healthGate, /health\.db\?\.ok !== true/);
assert.match(healthGate, /health\.mcp\?\.enabled !== true/);
assert.match(healthGate, /health\.mcp\?\.writesEnabled !== expectedWrites/);
assert.match(healthGate, /health\.mcp\?\.tools !== expectedTools/);
assert.match(deployScript, /MCP_BUILD_SHA="\$MCP_BUILD_SHA"/);
assert.match(deployScript, /DRY_RUN="\$\{DRY_RUN:-true\}"/);
assert.match(deployScript, /case "\$DRY_RUN" in\s+true\|false\)/);

console.log('MCP deploy guard PASS');
