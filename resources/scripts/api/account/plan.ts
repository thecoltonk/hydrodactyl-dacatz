import useSWR from 'swr';

import http from '@/api/http';
import type { ModpackHit, ModpackSource, ModpackVersion } from '@/api/server/modpacks';

export interface PlanResources {
    /** MiB */
    memory: number;
    /** percent, 100 = 1 core */
    cpu: number;
    /** MiB */
    disk: number;
}

export interface PlanEgg {
    id: number;
    name: string;
    description: string;
    nest: string;
}

export interface UserPlanInfo {
    plan: (PlanResources & { servers: number }) | null;
    used: PlanResources & { servers: number };
    minimum: PlanResources;
    eggs: PlanEgg[];
    /** Whether CurseForge modpacks are available (API key set). */
    curseforge?: boolean;
}

export const getPlan = async (): Promise<UserPlanInfo> => {
    const { data } = await http.get('/api/client/account/plan');
    return data as UserPlanInfo;
};

/** Shared, deduplicated plan lookup (dashboard bar, server rows, dialogs). */
export const usePlan = () => useSWR<UserPlanInfo>('account:plan', getPlan, { revalidateOnFocus: false });

export const createPlanServer = async (body: {
    name: string;
    egg_id?: number;
    memory: number;
    cpu: number;
    disk: number;
    modpack?: { source: ModpackSource; project_id: string; version_id: string };
}): Promise<{ identifier: string; uuid: string; operation_id: string | null }> => {
    const { data } = await http.post('/api/client/account/plan/servers', body);
    return data;
};

export const deletePlanServer = (uuid: string, confirmName: string) =>
    http.delete(`/api/client/servers/${uuid}/self-service`, { data: { confirm_name: confirmName } });

/** What's left in the plan, optionally giving back one server's current size (when resizing it). */
export const remaining = (info: UserPlanInfo, giveBack?: PlanResources): PlanResources => ({
    memory: Math.max(0, (info.plan?.memory ?? 0) - info.used.memory + (giveBack?.memory ?? 0)),
    cpu: Math.max(0, (info.plan?.cpu ?? 0) - info.used.cpu + (giveBack?.cpu ?? 0)),
    disk: Math.max(0, (info.plan?.disk ?? 0) - info.used.disk + (giveBack?.disk ?? 0)),
});

export const gb = (mib: number) => Math.round((mib / 1024) * 100) / 100;
export const cores = (percent: number) => Math.round(percent) / 100;

export const searchPlanModpacks = async (
    source: ModpackSource,
    query: string,
    offset = 0,
): Promise<{ hits: ModpackHit[]; total: number }> => {
    const params: Record<string, string | number> = { source, offset };
    if (query) params.query = query;
    const { data } = await http.get('/api/client/account/plan/modpacks/search', { params });
    return { hits: data?.hits ?? [], total: data?.total ?? 0 };
};

export const getPlanModpackVersions = async (source: ModpackSource, projectId: string): Promise<ModpackVersion[]> => {
    const { data } = await http.get('/api/client/account/plan/modpacks/versions', {
        params: { source, project_id: projectId },
    });
    return data?.versions ?? [];
};
