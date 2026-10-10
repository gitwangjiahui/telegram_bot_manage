package bots

import "strings"

// parseAdmins parses GROUP_CONCAT output "id:type,id:type".
// DECISIONS #1: first super wins.
func parseAdmins(raw string) (super int64, normals []int64) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return 0, nil
	}
	for _, pair := range strings.Split(raw, ",") {
		pair = strings.TrimSpace(pair)
		if pair == "" {
			continue
		}
		parts := strings.SplitN(pair, ":", 2)
		if len(parts) != 2 {
			continue
		}
		id := parseInt64(parts[0])
		switch parts[1] {
		case "super":
			if super == 0 {
				super = id
			}
		default:
			normals = append(normals, id)
		}
	}
	return super, normals
}

func parseInt64(s string) int64 {
	var n int64
	for _, c := range strings.TrimSpace(s) {
		if c < '0' || c > '9' {
			break
		}
		n = n*10 + int64(c-'0')
	}
	return n
}
