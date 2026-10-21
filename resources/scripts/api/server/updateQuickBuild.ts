import http from '@/api/http';

/** Set a server's RAM and storage (MiB) and CPU (% of one core). 0 = unlimited (admins only). */
export default async (
    uuid: string,
    memory: number,
    cpu: number,
    disk: number,
): Promise<{ memory: number; cpu: number; disk: number }> => {
    const { data } = await http.patch(`/api/client/servers/${uuid}/quick-build`, { memory, cpu, disk });
    return data;
};
