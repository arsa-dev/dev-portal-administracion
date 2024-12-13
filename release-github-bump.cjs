const analyzeCommits = async (pluginConfig, context) => {
  const { logger, options } = context;
  const ownerRepo = options.repositoryUrl?.replace("https://github.com/", "").replace('.git','').split("/") ?? [];
  const owner = ownerRepo[0];
  const repo = ownerRepo[1];

  // Dynamically import @octokit/rest
  const { Octokit } = await import("@octokit/rest");
  const octokit = new Octokit({ auth: process.env.GITHUB_TOKEN });

  // Fetch PRs merged into the current branch
  const { data: pulls } = await octokit.pulls.list({
    owner,
    repo,
    state: "closed",
    base: options.branches[0],
    per_page: 10,
  });

  // Get the last merged PR
  const mergedPR = pulls.filter((pr) => pr.merged_at).sort((a, b) => new Date(b.merged_at) - new Date(a.merged_at))[0];

  if (!mergedPR) {
    logger.log("No merged PR found, skipping release.");
    return null; // Skip release
  }

  logger.log(`Found merged PR: #${mergedPR.number} (${mergedPR.title})`);

  // Get the labels and determine version bump
  const labels = mergedPR.labels.map((label) => label.name);
  logger.log(`PR labels: ${labels}`);

  if (labels.includes("bump:major")) return "major";
  if (labels.includes("bump:minor")) return "minor";
  if (labels.includes("bump:patch")) return "patch";

  logger.log("No version bump label found. Skipping.");
  return null; // Skip release
};

const generateNotes = async (pluginConfig, context) => {
  const { env, logger, options } = context;
  const ownerRepo = options.repositoryUrl?.replace("https://github.com/", "").replace('.git','').split("/") ?? [];
  const owner = ownerRepo[0];
  const repo = ownerRepo[1];

  // Dynamically import @octokit/rest
  const { Octokit } = await import("@octokit/rest");
  const octokit = new Octokit({ auth: env.GITHUB_TOKEN });

  // Fetch PRs merged into the current branch
  const { data: pulls } = await octokit.pulls.list({
    owner,
    repo,
    state: "closed",
    base: options.branches[0],
    per_page: 10,
  });

  // Get the last merged PR
  const mergedPR = pulls.find((pr) => pr.merged_at);

  if (!mergedPR) {
    logger.log("No merged PR found, skipping notes generation.");
    return "";
  }

  logger.log(`Using description from PR #${mergedPR.number}`);
  return mergedPR.body || "No description provided.";
};

module.exports = {
  analyzeCommits,
  generateNotes,
};
