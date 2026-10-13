#include <stdint.h>
#include <stddef.h>

int64_t {{symbol}}(const char *s, size_t s_len, int32_t *forja_err)
{
    (void) forja_err;
    int64_t count = 0;
    for (size_t i = 0; i < s_len; i++) {
        switch (s[i]) {
            case 'a': case 'e': case 'i': case 'o': case 'u':
                count++;
        }
    }
    return count;
}
