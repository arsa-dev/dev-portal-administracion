const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const publish = async (pluginConfig, context) => {
  const { logger, env } = context;

  logger.log(env);

  const chartPath = env.HELM_CHART_PATH ?? "./portal-objetos-aprendizaje/values.yaml";
  const repoUrl = env.HELM_REPO_URL.replace('$GH_PAT', env.GH_PAT ?? '$GH_PAT'); // Example value: https://$GH_PAT@github.com/arsa-dev/helm-repo.git
  const projectValuesTag = env.HELM_PROJECT_VALUES_TAG ?? 'admin';
  const branch = env.RELEASE_BRANCH ?? "develop";

  logger.log(`Cloning repository ${env.HELM_REPO_URL}...`);
  const repoDir = path.join(__dirname, 'repo-temp');
  try {
    // Clone the repository
    execSync(`git clone -b ${branch} ${repoUrl} ${repoDir}`, { stdio: 'inherit' });

    // Read and update the Helm chart values
    logger.log("Updating Helm chart values...");
    const valuesPath = path.join(repoDir, chartPath);
    if (!fs.existsSync(valuesPath)) {
      throw new Error(`Helm values file not found at ${valuesPath}`);
    }

    const fileContent = fs.readFileSync(valuesPath, 'utf8').split('\n');
    const newTag = context.nextRelease.version; // Use the version from semantic-release context

    let updated = false, inProjectSection = false;
    const updatedContent = fileContent.map((line) => {
      if (updated) return line;

      if (line.startsWith(`${projectValuesTag}:`)) {
        inProjectSection = true;
      } else if (line[0] && line[0] !== ' ') {
        inProjectSection = false;
      }

      if (line.trim().startsWith("tag:") && inProjectSection) {
        logger.log(`Updating ${projectValuesTag} tag: "${line.trim()}" to "tag: ${newTag}"`);
        updated = true;
        inProjectSection = false;
        return line.replace(/tag:\s*".*"/, `tag: "${newTag}"`);
      }

      return line;
    });

    if (!updated) {
      throw new Error(`${projectValuesTag} tag field not found in values.yaml`);
    }

    // Write the updated content back to the file
    fs.writeFileSync(valuesPath, updatedContent.join('\n'), 'utf8');

    logger.log("Committing changes...");
    execSync(`git add ${chartPath}`, { cwd: repoDir, stdio: 'inherit' });
    execSync(`git commit -m "chore(release): update admin.image.tag to ${newTag}"`, {
      cwd: repoDir,
      stdio: 'inherit',
    });

    logger.log("Pushing changes...");
    // execSync(`git push origin ${branch}`, { cwd: repoDir, stdio: 'inherit' });
    logger.log("Helm chart updated successfully!");
  } catch (error) {
    logger.error("Error during Helm chart update:", error);
    throw error;
  } finally {
    // Clean up
    execSync(`rm -rf ${repoDir}`, { stdio: 'inherit' });
  }

  return null;
};

module.exports = {
  publish,
};