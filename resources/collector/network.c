// SPDX-License-Identifier: AGPL-3.0-or-later
// Socket I/O metadata only. Never read application buffers or environment.
#include <uapi/linux/ptrace.h>
#include <uapi/linux/in.h>
#include <uapi/linux/in6.h>
#include <linux/sched.h>
#include <net/sock.h>
#include <net/net_namespace.h>

struct context_t { char script[160]; u32 cron; };
struct call_t { struct sock *sk; struct msghdr *msg; u64 size; int flags; u8 protocol; u8 direction; u8 depth; };
struct event_t {
    u64 timestamp;
    u64 started;
    u64 bytes;
    u32 pid;
    u32 ppid;
    u32 netns;
    u16 family;
    u16 port;
    u8 protocol;
    u8 direction;
    u8 address[16];
    char comm[16];
    struct context_t context;
};
BPF_HASH(contexts, u32, struct context_t, 16384);
BPF_HASH(calls, u64, struct call_t, 16384);
BPF_PERCPU_ARRAY(scratch, struct event_t, 1);
BPF_PERCPU_ARRAY(exec_scratch, struct context_t, 1);
BPF_ARRAY(errors, u64, 3);
BPF_PERF_OUTPUT(events);

static __always_inline void missing(int index) {
    u64 *value = errors.lookup(&index);
    if (value) __sync_fetch_and_add(value, 1);
}

// Fixed stack offsets only: the verifier may lose the bounds of length after
// LLVM's signed/unsigned optimizations. Read the suffix through the user-memory
// helper instead of indexing arg[length-N] on the BPF stack.
static __always_inline int script_suffix(const char *ptr, int length) {
    char suffix[5] = {};
    if (length >= 5 && length < 160) {
        if (bpf_probe_read_user(suffix, 5, ptr + length - 5) == 0 &&
            suffix[0] == '.' && suffix[1] == 'p' && suffix[2] == 'h' &&
            suffix[3] == 'p' && suffix[4] == 0) return 1;
    }
    if (length >= 4 && length < 160) {
        if (bpf_probe_read_user(suffix, 4, ptr + length - 4) == 0 &&
            suffix[0] == '.' && suffix[3] == 0 &&
            ((suffix[1] == 'p' && suffix[2] == 'y') ||
             (suffix[1] == 'j' && suffix[2] == 's'))) return 1;
    }
    return 0;
}

// Only an entry script inside Jeedom's plugins/core and a numeric cron_id
// survive exec inspection. Arguments such as API keys never leave the kernel.
TRACEPOINT_PROBE(syscalls, sys_enter_execve) {
    int zero = 0;
    struct context_t *value = exec_scratch.lookup(&zero);
    if (!value) return 0;
    __builtin_memset(value, 0, sizeof(*value));
    u32 current_pid = bpf_get_current_pid_tgid() >> 32;
    struct context_t *inherited = contexts.lookup(&current_pid);
    if (inherited) __builtin_memcpy(value, inherited, sizeof(*value));
    char arg[160] = {};
    const char *ptr = 0;
    int found_script = 0;
    #pragma unroll
    for (int i = 0; i < 6; i++) {
        bpf_probe_read_user(&ptr, sizeof(ptr), &args->argv[i]);
        if (!ptr) break;
        __builtin_memset(arg, 0, sizeof(arg));
        int length = bpf_probe_read_user_str(arg, sizeof(arg), ptr);
        if (length <= 1 || length >= sizeof(arg)) continue;
        int is_script = script_suffix(ptr, length);
        if (!found_script && is_script && (SCRIPT_MATCH)) {
            __builtin_memcpy(value->script, arg, sizeof(arg));
            value->cron = 0;
            found_script = 1;
        }
        if (arg[0]=='c' && arg[1]=='r' && arg[2]=='o' && arg[3]=='n' &&
            arg[4]=='_' && arg[5]=='i' && arg[6]=='d' && arg[7]=='=') {
            u32 number = 0;
            int valid = 1;
            #pragma unroll
            for (int j = 8; j < 17; j++) {
                if (arg[j] == 0) break;
                if (arg[j] < '0' || arg[j] > '9') { valid = 0; break; }
                number = number * 10 + arg[j] - '0';
            }
            if (valid && arg[17] == 0) value->cron = number;
        }
    }
    u32 pid = bpf_get_current_pid_tgid() >> 32;
    if (contexts.update(&pid, value)) missing(0);
    return 0;
}
TRACEPOINT_PROBE(syscalls, sys_exit_execve) {
    if (args->ret < 0) { u32 pid = bpf_get_current_pid_tgid() >> 32; contexts.delete(&pid); }
    return 0;
}
TRACEPOINT_PROBE(sched, sched_process_fork) {
    u32 parent = bpf_get_current_pid_tgid() >> 32;
    u32 child = args->child_pid;
    struct context_t *value = contexts.lookup(&parent);
    if (value && contexts.update(&child, value)) missing(0);
    return 0;
}
TRACEPOINT_PROBE(sched, sched_process_exit) {
    u32 pid = args->pid;
    contexts.delete(&pid);
    u64 tid = bpf_get_current_pid_tgid();
    calls.delete(&tid);
    return 0;
}

static int enter(struct sock *sk, struct msghdr *msg, size_t size, int flags, u8 protocol, u8 direction) {
    u64 tid = bpf_get_current_pid_tgid();
    struct call_t *existing = calls.lookup(&tid);
    // udp v6 may delegate to udp v4 for mapped addresses: count only once.
    if (existing) { existing->depth++; return 0; }
    struct call_t call = {.sk=sk, .msg=msg, .size=size, .flags=flags, .protocol=protocol, .direction=direction};
    if (calls.update(&tid, &call)) missing(0);
    return 0;
}

// Pointers saved in a map are scalars to the verifier at kretprobe time.
// Every kernel-memory read must use a helper, including intermediate pointers.
static __always_inline int read_metadata(struct sock *sk, struct event_t *event) {
    struct task_struct *task = (struct task_struct *)bpf_get_current_task();
    struct task_struct *leader = 0;
    struct task_struct *parent = 0;
    struct net *network = 0;
    if (bpf_probe_read_kernel(&event->family, sizeof(event->family), &sk->__sk_common.skc_family)) return -1;
    if (event->family != AF_INET && event->family != AF_INET6) return 1;
    if (bpf_probe_read_kernel(&leader, sizeof(leader), &task->group_leader) || !leader) return -1;
    if (bpf_probe_read_kernel(&event->started, sizeof(event->started), &leader->start_time)) return -1;
    if (bpf_probe_read_kernel(&parent, sizeof(parent), &task->real_parent) || !parent) return -1;
    if (bpf_probe_read_kernel(&event->ppid, sizeof(event->ppid), &parent->tgid)) return -1;
    if (bpf_probe_read_kernel(&network, sizeof(network), &sk->__sk_common.skc_net.net) || !network) return -1;
    if (bpf_probe_read_kernel(&event->netns, sizeof(event->netns), &network->ns.inum)) return -1;
    if (bpf_probe_read_kernel(&event->port, sizeof(event->port), &sk->__sk_common.skc_dport)) return -1;
    if (event->family == AF_INET) {
        if (bpf_probe_read_kernel(event->address, 4, &sk->__sk_common.skc_daddr)) return -1;
    } else {
        if (bpf_probe_read_kernel(event->address, 16, &sk->__sk_common.skc_v6_daddr)) return -1;
    }
    return 0;
}

static int leave(struct pt_regs *ctx) {
    u64 tid = bpf_get_current_pid_tgid();
    struct call_t *call = calls.lookup(&tid);
    if (!call) return 0;
    if (call->depth) { call->depth--; return 0; }
    s64 count = PT_REGS_RC(ctx);
    // Ignore peeks/error queue. Truncated UDP reads count bytes copied, not the
    // original datagram size returned by MSG_TRUNC.
    if (count <= 0 || (call->direction == 0 && (call->flags & (2 | 8192)))) goto done;
    int zero = 0;
    struct event_t *event = scratch.lookup(&zero);
    if (!event) goto done;
    __builtin_memset(event, 0, sizeof(*event));
    struct sock *sk = call->sk;
    int metadata = read_metadata(sk, event);
    if (metadata) {
        if (metadata < 0) missing(2);
        goto done;
    }
    event->timestamp = bpf_ktime_get_ns();
    event->pid = tid >> 32;
    event->bytes = count > call->size ? call->size : count;
    event->protocol = call->protocol;
    event->direction = call->direction;
    if (call->protocol == 17) {
        struct msghdr *msg = call->msg;
        void *name = 0;
        if (bpf_probe_read_kernel(&name, sizeof(name), &msg->msg_name)) {
            missing(2);
            goto done;
        }
        if (name) {
            u16 family = 0;
            if (bpf_probe_read_kernel(&family, sizeof(family), name)) {
                missing(2);
                goto done;
            }
            if (family == AF_INET) {
                struct sockaddr_in addr = {};
                if (bpf_probe_read_kernel(&addr, sizeof(addr), name)) { missing(2); goto done; }
                event->family = AF_INET;
                event->port = addr.sin_port;
                __builtin_memset(event->address, 0, 16);
                __builtin_memcpy(event->address, &addr.sin_addr, 4);
            } else if (family == AF_INET6) {
                struct sockaddr_in6 addr = {};
                if (bpf_probe_read_kernel(&addr, sizeof(addr), name)) { missing(2); goto done; }
                event->family = AF_INET6;
                event->port = addr.sin6_port;
                __builtin_memcpy(event->address, &addr.sin6_addr, 16);
            }
        }
    }
    bpf_get_current_comm(event->comm, sizeof(event->comm));
    u32 pid = event->pid;
    struct context_t *source = contexts.lookup(&pid);
    if (source) __builtin_memcpy(&event->context, source, sizeof(*source));
    if (events.perf_submit(ctx, event, sizeof(*event)) < 0) missing(1);
done:
    calls.delete(&tid);
    return 0;
}
int tcp_tx(struct pt_regs *ctx, struct sock *sk, struct msghdr *msg, size_t size) { return enter(sk, msg, size, 0, 6, 1); }
int tcp_rx(struct pt_regs *ctx, struct sock *sk, struct msghdr *msg, size_t size, int flags) { return enter(sk, msg, size, flags, 6, 0); }
int udp_tx(struct pt_regs *ctx, struct sock *sk, struct msghdr *msg, size_t size) { return enter(sk, msg, size, 0, 17, 1); }
int udp_rx(struct pt_regs *ctx, struct sock *sk, struct msghdr *msg, size_t size, int flags) { return enter(sk, msg, size, flags, 17, 0); }
int io_return(struct pt_regs *ctx) { return leave(ctx); }
