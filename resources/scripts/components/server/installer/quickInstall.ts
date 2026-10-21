import pullFile from '@/api/server/files/pullFile';
import {
    getMarketplaceProject,
    type MarketplaceProject,
    type MarketplaceType,
    type MarketplaceVersion,
    resolveMarketplaceInstall,
} from '@/api/server/marketplace';

import { destinationFolder } from './eggFeatures';
import { recordInstall } from './installedState';

const newestFirst = (versions: MarketplaceVersion[]) =>
    [...versions].sort((a, b) => (b.date ? Date.parse(b.date) : 0) - (a.date ? Date.parse(a.date) : 0));

/**
 * Install the newest version of a project that matches the server's loader and
 * Minecraft version (stable releases preferred). Mods never fall back to a
 * different Minecraft version; plugins do, since most sources don't tag them.
 */
export async function quickInstall({
    uuid,
    type,
    project,
    loader,
    gameVersion,
}: {
    uuid: string;
    type: MarketplaceType;
    project: MarketplaceProject;
    loader: string | null;
    gameVersion: string | null;
}): Promise<{ filename: string; version: string }> {
    const lookup = (version: string | null) =>
        getMarketplaceProject(uuid, {
            type,
            source: project.source,
            projectId: project.id,
            loader,
            gameVersion: version,
        });

    let versions = await lookup(gameVersion);
    if (versions.length === 0 && type === 'plugin' && gameVersion) {
        versions = await lookup(null);
    }
    const ordered = newestFirst(versions).filter((version) => !/CurseForge download only/u.test(version.name));
    const version = ordered.find((entry) => entry.type === 'release') ?? ordered[0];
    if (!version) {
        throw new Error(
            `No version of ${project.title} for ${[loader, gameVersion].filter(Boolean).join(' ') || 'this server'}.`,
        );
    }

    const resolved = await resolveMarketplaceInstall(uuid, {
        type,
        source: project.source,
        projectId: project.id,
        versionId: version.id,
    });
    const directory = resolved.directory || destinationFolder(type);
    await pullFile(uuid, { url: resolved.url, directory, filename: resolved.filename });
    try {
        await recordInstall(uuid, directory, project.source, project.id, {
            filename: resolved.filename,
            version_id: version.id,
            version_name: version.name,
            project_title: project.title,
            installed_at: new Date().toISOString(),
        });
    } catch {
        // The file is on disk; the install record is best-effort.
    }

    return { filename: resolved.filename, version: version.name };
}
