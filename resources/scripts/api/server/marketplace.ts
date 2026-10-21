import http from '@/api/http';

export type MarketplaceType = 'mod' | 'plugin';

export interface MarketplaceProject {
    source: string;
    id: string;
    title: string;
    slug: string;
    description: string;
    author: string;
    icon: string | null;
    downloads: number;
    categories: string[];
    url: string | null;
    updated_at: string | null;
}

export interface MarketplaceVersion {
    id: string;
    name: string;
    loaders: string[];
    game_versions: string[];
    size: number | null;
    date: string | null;
    type: string;
}

export interface MarketplaceSourceMeta {
    key: string;
    label: string;
}

export interface MarketplaceResolvedInstall {
    url: string;
    filename: string;
    size: number | null;
    directory: string;
}

/**
 * Build a query-param object, omitting null/undefined/empty values so axios
 * never sends e.g. "source=" (which would fail Laravel's alpha_dash validation).
 */
const cleanParams = (input: Record<string, unknown>): Record<string, string | number> => {
    const out: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(input)) {
        if (value === null || value === undefined || value === '') continue;
        out[key] = value as string | number;
    }

    return out;
};

interface SearchParams {
    type: MarketplaceType;
    source?: string | null;
    query?: string;
    loader?: string | null;
    game_version?: string | null;
    limit?: number;
    offset?: number;
}

/**
 * Search the marketplace. Mounted under the daemon-agnostic
 * `/api/client/servers/{server}/marketplace` group, so no daemon segment.
 */
export const searchMarketplace = async (
    uuid: string,
    params: SearchParams,
): Promise<{ results: MarketplaceProject[]; sources: MarketplaceSourceMeta[] }> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/marketplace/search`, {
        params: cleanParams({
            type: params.type,
            source: params.source,
            query: params.query,
            loader: params.loader,
            game_version: params.game_version,
            limit: params.limit ?? 20,
            offset: params.offset ?? 0,
        }),
    });

    return {
        results: (data?.results as MarketplaceProject[] | undefined) ?? [],
        sources: (data?.sources as MarketplaceSourceMeta[] | undefined) ?? [],
    };
};

export const getMarketplaceProject = async (
    uuid: string,
    {
        type,
        source,
        projectId,
        loader,
        gameVersion,
    }: {
        type: MarketplaceType;
        source: string;
        projectId: string;
        loader?: string | null;
        gameVersion?: string | null;
    },
): Promise<MarketplaceVersion[]> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/marketplace/project`, {
        params: cleanParams({
            type,
            source,
            project_id: projectId,
            loader,
            game_version: gameVersion,
        }),
    });

    return (data?.versions as MarketplaceVersion[] | undefined) ?? [];
};

export const resolveMarketplaceInstall = async (
    uuid: string,
    {
        type,
        source,
        projectId,
        versionId,
    }: {
        type: MarketplaceType;
        source: string;
        projectId: string;
        versionId: string;
    },
): Promise<MarketplaceResolvedInstall> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/marketplace/resolve`, {
        params: { type, source, project_id: projectId, version_id: versionId },
    });

    return data as MarketplaceResolvedInstall;
};

/**
 * The cached Modrinth loader-tag list — the authoritative set of loader names,
 * used to validate the loader extracted from a server's egg features so the
 * installer picks up new loaders Modrinth adds without a code change.
 */
export const getMarketplaceLoaders = async (uuid: string): Promise<string[]> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/marketplace/loaders`);

    return Array.isArray((data as { loaders?: string[] })?.loaders) ? data.loaders : [];
};

export interface GameVersions {
    [type: string]: string[];
}

export const getMarketplaceGameVersions = async (uuid: string): Promise<GameVersions> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/marketplace/game-versions`);

    return (data as { game_versions?: GameVersions })?.game_versions ?? {};
};

export interface ModCollectionItem {
    type: MarketplaceType;
    project: MarketplaceProject;
}

export interface ModCollection {
    id: number;
    name: string;
    items: ModCollectionItem[];
}

const COLLECTIONS = '/api/client/account/mod-collections';

/** The signed-in user's private collections (shared across all their servers). */
export const getModCollections = async (): Promise<ModCollection[]> => {
    const { data } = await http.get(COLLECTIONS);
    return (data?.collections as ModCollection[] | undefined) ?? [];
};

export const createModCollection = async (name: string): Promise<ModCollection> => {
    const { data } = await http.post(COLLECTIONS, { name });
    return data.collection as ModCollection;
};

export const renameModCollection = (id: number, name: string) => http.patch(`${COLLECTIONS}/${id}`, { name });

export const deleteModCollection = (id: number) => http.delete(`${COLLECTIONS}/${id}`);

export const addToModCollection = (id: number, type: MarketplaceType, project: MarketplaceProject) =>
    http.post(
        `${COLLECTIONS}/${id}/items`,
        cleanParams({
            type,
            source: project.source,
            project_id: project.id,
            title: project.title,
            slug: project.slug,
            author: project.author,
            description: project.description?.slice(0, 2000),
            icon: project.icon?.startsWith('https://') ? project.icon : null,
            url: project.url?.startsWith('https://') ? project.url : null,
            downloads: project.downloads,
        }),
    );

export const removeFromModCollection = (id: number, type: MarketplaceType, project: MarketplaceProject) =>
    http.delete(`${COLLECTIONS}/${id}/items`, {
        params: { type, source: project.source, project_id: project.id },
    });
