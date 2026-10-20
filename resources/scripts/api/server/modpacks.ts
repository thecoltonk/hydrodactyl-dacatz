import http from '@/api/http';
import type { ServerOperation } from '@/api/server/serverOperations';

export type ModpackLoader = 'fabric' | 'quilt' | 'forge' | 'neoforge';

export type ModpackSource = 'modrinth' | 'curseforge';

export interface ModpackHit {
    id: string;
    slug: string;
    title: string;
    description: string;
    author: string;
    icon: string | null;
    downloads: number;
    loaders: ModpackLoader[];
    game_versions: string[];
    url: string | null;
}

export interface ModpackVersion {
    id: string;
    name: string;
    version_number: string;
    game_versions: string[];
    loaders: ModpackLoader[];
    type: string;
    date: string | null;
    server_pack?: boolean;
}

export interface ModpackInstallResult {
    operation_id: string;
    title: string;
    version_name: string;
    loader: ModpackLoader;
    loader_version: string;
    minecraft: string;
    files: number;
    server_pack: boolean;
    skipped: string[];
}

export const searchModpacks = async (
    uuid: string,
    source: ModpackSource,
    query: string,
    loader: ModpackLoader | null,
    offset: number,
): Promise<{ hits: ModpackHit[]; total: number }> => {
    const params: Record<string, string | number> = { offset, source };
    if (query) params.query = query;
    if (loader) params.loader = loader;
    const { data } = await http.get(`/api/client/servers/${uuid}/modpacks/search`, { params });
    return { hits: data?.hits ?? [], total: data?.total ?? 0 };
};

export const getModpackVersions = async (
    uuid: string,
    source: ModpackSource,
    projectId: string,
): Promise<ModpackVersion[]> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/modpacks/versions`, {
        params: { project_id: projectId, source },
    });
    return data?.versions ?? [];
};

export const installModpack = async (
    uuid: string,
    source: ModpackSource,
    projectId: string,
    versionId: string,
): Promise<ModpackInstallResult> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/modpacks/install`, {
        source,
        project_id: projectId,
        version_id: versionId,
        confirm_wipe: true,
    });
    return data;
};

export const getModpackStatus = async (uuid: string): Promise<ServerOperation | null> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/modpacks/status`);
    return data?.operation ?? null;
};
