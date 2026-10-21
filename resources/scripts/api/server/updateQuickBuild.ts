import http from '@/api/http';

/** Admin only: set a server's RAM (MiB) and CPU (% of one core). 0 = unlimited. */
export default async (uuid: string, memory: number, cpu: number): Promise<{ memory: number; cpu: number }> => {
    const { data } = await http.patch(`/api/client/servers/${uuid}/quick-build`, { memory, cpu });
    return data;
};
